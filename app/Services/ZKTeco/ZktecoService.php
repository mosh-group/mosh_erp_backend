<?php

namespace App\Services\ZKTeco;

use App\Models\{Employee, ZktecoDevice, ZktecoEmployeeMapping, ZktecoAttendanceEvent};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\CarbonImmutable;
use Throwable;

class ZktecoService
{
    public function ensurePrimaryDevice(): ZktecoDevice
    {
        $c = config('zkteco.primary_device');
        return ZktecoDevice::firstOrCreate(['id' => $c['id']], ['name' => $c['name'], 'serial' => $c['serial'] ?: null, 'ip' => $c['ip'] ?: null, 'port' => $c['port'], 'timezone' => $c['timezone'], 'active' => true, 'status' => 'configured']);
    }
    public function syncPrimaryMapping(Employee $employee): void
    {
        if (!config('zkteco.enabled')) return;
        $d = $employee->data ?? [];
        $device = $this->ensurePrimaryDevice();
        $enroll = trim((string)($d['zktecoEnrollmentNumber'] ?? ''));
        $exempt = (bool)($d['zktecoExempt'] ?? false);
        if ($exempt || $enroll === '') {
            ZktecoEmployeeMapping::where(['device_id' => $device->id, 'employee_id' => $employee->id])->delete();
            return;
        }
        $conflict = ZktecoEmployeeMapping::where(['device_id' => $device->id, 'enrollment_number' => $enroll])->where('employee_id', '!=', $employee->id)->exists();
        abort_if($conflict, 422, 'ZKTeco enrollment number is already mapped on the primary device.');
        ZktecoEmployeeMapping::updateOrCreate(['device_id' => $device->id, 'employee_id' => $employee->id], ['enrollment_number' => $enroll, 'active' => true]);
    }
    public function ingest(array $v): array
    {
        $result = DB::transaction(function () use ($v) {
            $device = ZktecoDevice::whereKey($v['deviceId'])->where('active', true)->lockForUpdate()->first();
            abort_unless($device, 422, 'Unknown or inactive ZKTeco device.');
            $dt = CarbonImmutable::parse($v['deviceTime'], $device->timezone ?: 'UTC');
            $key = $v['eventKey'] ?? hash('sha256', implode('|', [$device->id, $v['enrollmentNumber'], $dt->format('Y-m-d H:i:s'), $v['verifyMethod'] ?? '', $v['attState'] ?? '', $v['workCode'] ?? '', $v['isInvalid'] ?? 0]));
            if ($old = ZktecoAttendanceEvent::where('event_key', $key)->first()) return ['duplicate' => true, 'event' => $old];
            $mapping = ZktecoEmployeeMapping::where(['device_id' => $device->id, 'enrollment_number' => (string)$v['enrollmentNumber'], 'active' => true])->first();
            $event = ZktecoAttendanceEvent::create(['id' => (string)Str::ulid(), 'event_key' => $key, 'device_id' => $device->id, 'enrollment_number' => (string)$v['enrollmentNumber'], 'employee_id' => $mapping?->employee_id, 'device_time' => $dt, 'received_at' => now(), 'verify_method' => $v['verifyMethod'] ?? null, 'att_state' => $v['attState'] ?? null, 'work_code' => $v['workCode'] ?? null, 'is_invalid' => $v['isInvalid'] ?? 0, 'source' => $v['source'] ?? 'event', 'processing_status' => $mapping ? 'pending' : 'unmapped', 'payload' => $v]);
            $device->update(['last_seen_at' => now(), 'last_sync_at' => now(), 'status' => 'online']);
            return ['duplicate' => false, 'event' => $event];
        });
        $event = $result['event'];
        if ($result['duplicate']) return ['eventId' => $event->id, 'status' => 'duplicate', 'processingStatus' => $event->processing_status];
        if ($event->employee_id) {
            try {
                $this->process($event);
            } catch (Throwable $e) {
                $event->update(['processing_status' => 'error', 'processing_error' => substr($e->getMessage(), 0, 4000)]);
                report($e);
            }
        }
        return ['eventId' => $event->id, 'status' => 'accepted', 'processingStatus' => $event->fresh()->processing_status];
    }
    public function process(ZktecoAttendanceEvent $event): void
    {
        DB::transaction(fn() => $this->processLocked($event->fresh()));
    }
    private function processLocked(ZktecoAttendanceEvent $event): void
    {
        if (!$event->employee_id) {
            $event->update(['processing_status' => 'unmapped']);
            return;
        }
        $employee = Employee::lockForUpdate()->find($event->employee_id);
        if (!$employee) {
            $event->update(['processing_status' => 'unmapped', 'employee_id' => null]);
            return;
        }
        $device = ZktecoDevice::findOrFail($event->device_id);
        if ((int) $event->is_invalid === 1) {

            $event->update([

                'processing_status' => 'processed',

                'ignored_for_attendance' => true,

                'ignore_reason' => 'device_marked_invalid',

                'processed_at' => now(),

            ]);

            return;
        }
        $tz = $device->timezone ?: 'UTC';
        $date = $event->device_time->copy()->format('Y-m-d');
        $previous = ZktecoAttendanceEvent::where('employee_id', $employee->id)->where('processing_status', 'processed')->where('ignored_for_attendance', false)->where('device_time', '<=', $event->device_time)->orderByDesc('device_time')->first();
        if ($previous && abs($event->device_time->diffInSeconds($previous->device_time, false)) < 60) {
            $event->update(['processing_status' => 'processed', 'ignored_for_attendance' => true, 'ignore_reason' => 'repeat_within_60_seconds', 'processed_at' => now()]);
            return;
        }
        $events = ZktecoAttendanceEvent::where('employee_id', $employee->id)->where('id', '!=', $event->id)->where('processing_status', 'processed')->where('ignored_for_attendance', false)->get()->filter(fn($e) => $e->device_time->copy()->format('Y-m-d') === $date)->push($event)->sortBy('device_time')->values();
        $first = $events->first();
        $last = $events->last();
        $data = $employee->data ?? [];
        $records = $data['attendanceRecords'] ?? [];
        $index = null;
        foreach ($records as $i => $r) if (($r['date'] ?? '') === $date && ($r['source'] ?? '') === 'zkteco') {
            $index = $i;
            break;
        }
        $manual = false;
        foreach ($records as $r) if (($r['date'] ?? '') === $date && ($r['source'] ?? '') !== 'zkteco' && ($r['status'] ?? '') !== 'cancelled') {
            $manual = true;
            break;
        }
        $record = ['id' => $index !== null ? ($records[$index]['id'] ?? 'ZK-' . $date) : 'ZK-' . $employee->id . '-' . $date, 'date' => $date, 'inTime' => $first->device_time->copy()->format('H:i'), 'outTime' => $events->count() > 1 ? $last->device_time->copy()->format('H:i') : null, 'breakMinutes' => 0, 'type' => 'attendance', 'source' => 'zkteco', 'status' => $manual ? 'overridden' : 'recorded', 'needsHrReview' => $events->count() === 1, 'zktecoDeviceId' => $device->id, 'zktecoEventCount' => $events->count()];
        if ($index === null) $records[] = $record;
        else $records[$index] = $record;
        $data['attendanceRecords'] = array_values($records);
        $data['updatedAt'] = now()->toIso8601String();
        $data['updatedBy'] = 'ZKTeco Integration';
        $employee->update(['data' => $data, 'version' => $employee->version + 1]);
        $event->update([
    'processing_status' => 'processed',
    'ignored_for_attendance' => false,
    'ignore_reason' => null,
    'processed_at' => now(),
    'processing_error' => null,
]);
    }
    public function replay(ZktecoAttendanceEvent $event): void
    {
        $mapping = ZktecoEmployeeMapping::where(['device_id' => $event->device_id, 'enrollment_number' => $event->enrollment_number, 'active' => true])->first();
        abort_unless($mapping, 422, 'No employee mapping exists for this event.');
        $event->update(['employee_id' => $mapping->employee_id, 'processing_status' => 'pending', 'processing_error' => null]);
        try {
            $this->process($event);
        } catch (Throwable $e) {
            $event->update(['processing_status' => 'error', 'processing_error' => substr($e->getMessage(), 0, 4000)]);
            throw $e;
        }
    }
}

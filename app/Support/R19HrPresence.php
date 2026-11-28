<?php

namespace App\Support;

use App\Models\{Employee, Setting, User};
use App\Services\R29\Rules;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

final class R19HrPresence
{
    public static function today(User $actor): array
    {
        abort_unless(R19HrAccess::hr($actor, 'reports') || R19HrAccess::hr($actor, 'details'), 403);
        $now = now()->toImmutable()->setTimezone(new DateTimeZone(config('app.timezone', 'Asia/Damascus')));

        return DB::transaction(function () use ($now) {
            $employees = Employee::orderBy('id')->get()->filter(
                fn (Employee $employee) => R19HrAccess::activeEmployee($employee) && empty($employee->data['archivedAt'])
            );
            $settings = Setting::whereIn('id', $employees->map(fn (Employee $employee) => 'r29:hr:'.$employee->id)->all())
                ->get(['id', 'value'])->keyBy('id');

            $counts = array_fill_keys(['total', 'present', 'left', 'waiting', 'absent', 'late', 'leave', 'unscheduled', 'off', 'review'], 0);
            $rows = [];
            foreach ($employees as $employee) {
                $row = self::employee($employee, $settings->get('r29:hr:'.$employee->id)?->value ?? [], $now);
                $rows[] = $row;
                $counts['total']++;
                $counts[$row['status']]++;
                if ($row['delayMinutes'] > 0) {
                    $counts['late']++;
                }
            }

            return ['date' => $now->format('Y-m-d'), 'asOf' => $now->format(DATE_ATOM),
                'timeZone' => $now->getTimezone()->getName(), 'counts' => $counts, 'rows' => $rows];
        });
    }

    private static function employee(Employee $employee, array $settings, DateTimeImmutable $now): array
    {
        $data = $employee->data ?? [];
        $day = $now->setTime(0, 0);
        $yesterday = $day->modify('-1 day');
        $todaySchedule = self::schedule($settings, $day);
        $overnightSchedule = self::schedule($settings, $yesterday);
        $attendance = self::attendance($data, $settings, $day, $now, $todaySchedule, $overnightSchedule);
        $start = $todaySchedule['start'] ?? null;
        $end = $todaySchedule['end'] ?? null;
        [$approvedDay, $approvedHours] = self::leaves($data, $day);
        $expected = self::allowedArrival($start, $approvedHours);
        $hourlyLeaveNow = false;
        foreach ($approvedHours as [$from, $to]) {
            $hourlyLeaveNow = $hourlyLeaveNow || ($now >= $from && $now < $to);
        }
        $leaveType = $approvedDay ?? ($hourlyLeaveNow || ($end && $expected >= $end) ? 'ساعية' : null);

        $delay = null;
        if ($attendance['firstToday'] && !$approvedDay && (!$start || $expected < $end)) {
            $delay = $expected ? self::delay($expected, $attendance['firstToday'], $approvedHours)
                : self::recordedDelay($attendance['firstToday'], $attendance['firstRecord'], $day);
        }

        [$status, $reason] = match (true) {
            $attendance['open'] && !$attendance['unresolved'] => ['present', 'تم تسجيل الدخول ولم يُسجّل الخروج بعد.'],
            $leaveType !== null => ['leave', 'إجازة معتمدة.'],
            $attendance['unresolved'] => ['review', 'تحتاج أوقات الحضور إلى مراجعة الموارد البشرية.'],
            $attendance['out'] !== null => ['left', 'تم تسجيل الخروج.'],
            ($todaySchedule['off'] ?? false) => ['off', 'يوم راحة حسب جدول الدوام.'],
            $start === null => ['unscheduled', 'لا يوجد جدول دوام لهذا اليوم.'],
            $now >= $end => ['absent', 'انتهى الدوام المحدد ولم يُسجّل دخول.'],
            $now < $expected => ['waiting', 'لم يحن وقت بدء الدوام.'],
            default => ['waiting', 'لم يُسجّل دخول بعد.'],
        };

        return ['employeeId' => (string) $employee->id, 'name' => (string) ($data['name'] ?? ''),
            'department' => (string) ($data['department'] ?? ''), 'status' => $status,
            'inDate' => $attendance['in']?->format('Y-m-d'), 'outDate' => $attendance['out']?->format('Y-m-d'),
            'inTime' => $attendance['in']?->format('H:i'), 'outTime' => $attendance['out']?->format('H:i'),
            'scheduledStart' => $start?->format('H:i'), 'scheduledEnd' => $end?->format('H:i'),
            'delayMinutes' => $delay, 'leaveType' => $leaveType, 'reason' => $reason,
            'source' => $attendance['source']];
    }

    private static function schedule(array $settings, DateTimeImmutable $day): ?array
    {
        $date = $day->format('Y-m-d');
        $weekday = $day->format('w');
        $priority = '';
        $match = null;
        foreach ($settings['schedules'] ?? [] as $schedule) {
            if (!is_array($schedule)) {
                continue;
            }
            $from = $schedule['effectiveFrom'] ?? '';
            $through = $schedule['effectiveTo'] ?? '';
            $type = $schedule['type'] ?? '';
            if (!is_string($from) || !Rules::date($from) || $from > $date || ($through !== '' && $through < $date)
                || !in_array($type, ['weekly', 'monthly'], true)) {
                continue;
            }
            $key = $type === 'monthly' ? 'date' : 'day';
            $wanted = $type === 'monthly' ? $date : $weekday;
            $rank = $from.($type === 'monthly' ? '1' : '0');
            if ($rank < $priority) {
                continue;
            }
            foreach ($schedule['rows'] ?? [] as $row) {
                if (is_array($row) && (string) ($row[$key] ?? '') === $wanted) {
                    $priority = $rank;
                    $match = $row;
                    break;
                }
            }
        }

        if ($match['off'] ?? false) {
            return ['off' => true];
        }
        $start = self::clock($day, $match['start'] ?? null);
        $end = self::clock($day, $match['end'] ?? null);
        if (!$start || !$end || $start == $end) {
            return null;
        }

        return ['start' => $start, 'end' => $end < $start ? $end->modify('+1 day') : $end];
    }

    private static function attendance(array $data, array $settings, DateTimeImmutable $day, DateTimeImmutable $now,
        ?array $todaySchedule, ?array $overnightSchedule): array
    {
        $today = $day->format('Y-m-d');
        $yesterday = $day->modify('-1 day');
        $previousDay = $yesterday->format('Y-m-d');
        $first = $firstToday = $lastIn = $lastOut = $source = null;
        $firstRecord = [];
        $unresolved = $open = $staleOpen = false;

        foreach ($data['attendanceRecords'] ?? [] as $record) {
            if (!is_array($record) || in_array($record['status'] ?? '', ['cancelled', 'rejected', 'overridden'], true)) {
                continue;
            }
            $date = $record['date'] ?? $record['workDate'] ?? '';
            if ($date !== $today && $date !== $previousDay) {
                continue;
            }
            $isToday = $date === $today;
            $recordDay = $isToday ? $day : $yesterday;
            $recordSource = (string) ($record['source'] ?? 'hr');
            $exactIn = $record['startAt'] ?? null;
            $exactOut = $record['endAt'] ?? null;
            $exit = $record['outTime'] ?? $record['checkOut'] ?? $record['lastOut'] ?? null;
            $entry = $record['inTime'] ?? $record['checkIn'] ?? $record['firstIn'] ?? null;
            $exitDate = $record['outDate'] ?? '';
            $in = $exactIn ? self::timestamp($exactIn, $recordDay) : self::clock($recordDay, $entry);
            $outDay = $exitDate === $recordDay->modify('+1 day')->format('Y-m-d') ? $recordDay->modify('+1 day') : $recordDay;
            $out = $exactOut ? self::timestamp($exactOut, $recordDay) : self::clock($outDay, $exit);
            if ($out && $in && $out < $in && !$exactOut && $exitDate === '' && $recordSource !== 'zkteco') {
                $out = $out->modify('+1 day');
            }

            $selfOpen = $recordSource === 'self_r29' && !empty($record['id'])
                && ($settings['openShift']['id'] ?? null) === $record['id'];
            $scheduledEnd = ($isToday ? $todaySchedule : $overnightSchedule)['end'] ?? null;
            $openOvernight = !$isToday && !$out && ($selfOpen || ($scheduledEnd && $scheduledEnd >= $day));
            if (!$isToday && !$openOvernight && (!$out || $out < $day)) {
                continue;
            }

            if (!$in || $in->format('Y-m-d') !== $date || $in > $now
                || (($exactOut || $exit) && !$out)
                || ($exitDate !== '' && $out && $out->format('Y-m-d') !== $exitDate)
                || ($out && ($out < $in || $out > $now || $out->getTimestamp() - $in->getTimestamp() > 86400))) {
                $unresolved = true;
                continue;
            }

            if ($isToday && (!$firstToday || $in < $firstToday)) {
                $firstToday = $in;
                $firstRecord = $record;
            }
            if (!$first || $in < $first) {
                $first = $in;
            }
            if (!$lastIn || $in >= $lastIn) {
                $lastIn = $in;
                $source = $recordSource;
                $open = !$out;
                $staleOpen = $open && ($now->getTimestamp() - $in->getTimestamp() >= 86400
                    || (!$selfOpen && $scheduledEnd && $now >= $scheduledEnd));
            }
            if ($out && (!$lastOut || $out > $lastOut)) {
                $lastOut = $out;
            }
        }

        $open = $open && (!$lastOut || $lastIn > $lastOut);
        return ['firstToday' => $firstToday, 'firstRecord' => $firstRecord, 'in' => $first, 'out' => $lastOut,
            'open' => $open, 'unresolved' => $unresolved || ($open && $staleOpen), 'source' => $source];
    }

    private static function leaves(array $data, DateTimeImmutable $day): array
    {
        $date = $day->format('Y-m-d');
        $fullDay = null;
        $hours = [];
        foreach ($data['leaveRecords'] ?? [] as $leave) {
            if (!is_array($leave) || ($leave['status'] ?? '') !== 'approved') {
                continue;
            }
            $from = $leave['startDate'] ?? $leave['date'] ?? '';
            $to = $leave['endDate'] ?? $from;
            if (!is_string($from) || !is_string($to) || !Rules::date($from) || !Rules::date($to) || $from > $date || $to < $date) {
                continue;
            }
            $type = (string) ($leave['type'] ?? '');
            if (!in_array($type, ['hourly', 'hourly_leave', 'ساعية'], true)) {
                $fullDay = match ($type) {
                    'annual', 'annual_leave' => 'سنوية',
                    'sick', 'sick_leave' => 'مرضية',
                    default => $type !== '' ? $type : 'إجازة',
                };
                continue;
            }

            $start = self::clock($day, $leave['fromTime'] ?? null);
            $end = self::clock($day, $leave['toTime'] ?? null);
            if ($start && $end && $start < $end) {
                $hours[] = [$start, $end];
            }
        }
        usort($hours, fn (array $a, array $b) => $a[0] <=> $b[0]);

        return [$fullDay, $hours];
    }

    private static function allowedArrival(?DateTimeImmutable $start, array $leaveHours): ?DateTimeImmutable
    {
        if ($start) {
            foreach ($leaveHours as [$from, $to]) {
                if ($from <= $start && $to > $start) {
                    $start = $to;
                }
            }
        }

        return $start;
    }

    private static function delay(DateTimeImmutable $expected, DateTimeImmutable $arrival, array $leaveHours): int
    {
        $cursor = $expected;
        $seconds = 0;
        foreach ($leaveHours as [$from, $to]) {
            if ($to <= $cursor || $from >= $arrival) {
                continue;
            }
            $seconds += max(0, $from->getTimestamp() - $cursor->getTimestamp());
            $cursor = $to;
        }

        return intdiv($seconds + max(0, $arrival->getTimestamp() - $cursor->getTimestamp()), 60);
    }

    private static function recordedDelay(DateTimeImmutable $in, array $record, DateTimeImmutable $day): ?int
    {
        $delay = $record['delayMinutes'] ?? null;
        if (is_numeric($delay) && $delay >= 0 && $delay <= 1440) {
            return (int) $delay;
        }
        $start = self::clock($day, $record['scheduledStart'] ?? $record['scheduledIn'] ?? null);

        return $start ? max(0, intdiv($in->getTimestamp() - $start->getTimestamp(), 60)) : null;
    }

    private static function clock(DateTimeImmutable $day, mixed $time): ?DateTimeImmutable
    {
        if (!is_string($time) || !preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/D', $time, $parts)) {
            return null;
        }

        return $day->setTime((int) $parts[1], (int) $parts[2], (int) ($parts[3] ?? 0));
    }

    private static function timestamp(mixed $value, DateTimeImmutable $day): ?DateTimeImmutable
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}[T ](?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:?\d{2})?$/D', $value)
            || !Rules::date(substr($value, 0, 10))) {
            return null;
        }

        try {
            return (new DateTimeImmutable($value, $day->getTimezone()))->setTimezone($day->getTimezone());
        } catch (\Exception) {
            return null;
        }
    }
}

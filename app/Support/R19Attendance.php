<?php
namespace App\Support;

use App\Models\{Employee, User};

/** Attendance is company attendance, not the employee's task stopwatch.
 * ZKTeco writes its reconciled records into employee.attendanceRecords; using
 * that one authority avoids counting both the swipe and its materialization.
 * Missing swipes are never replaced by manufactured work-presence intervals. */
final class R19Attendance
{
    public static function id(string $employeeId, string $date): string
    {
        return 'OT19-'.$date.'-'.substr(hash('sha256', $employeeId.'|'.$date), 0, 32);
    }

    public static function forEmployee(Employee $employee, string $date, ?array $approval = null): ?array
    {
        $data = $employee->data ?? [];
        $person = ['id' => (string)$employee->id, 'name' => (string)($data['name'] ?? ''),
            'department' => (string)($data['department'] ?? ''), 'directManagerId' => R19HrAccess::directManagerId($employee)];
        return self::calculate($person, $date, $data['attendanceRecords'] ?? [], $approval,
            now()->getTimestampMs(), (string)config('app.timezone', 'Asia/Damascus'));
    }

    /** Pure calculation for real source records; dates belong to the recorded
     * shift date, including an explicit overnight exit on the following day. */
    public static function calculate(array $employee, string $date, array $records, ?array $approval, int $asOf, string $zone): ?array
    {
        [$dayStart, $dayEnd] = WorkdayReport::bounds($date, $zone);
        $source = [];
        foreach ($records as $record) {
            if (!is_array($record) || (string)($record['date'] ?? $record['workDate'] ?? '') !== $date) continue;
            if (in_array($record['status'] ?? '', ['cancelled', 'rejected', 'overridden'], true)) continue;
            $source[] = array_intersect_key($record, array_flip(['id', 'date', 'workDate', 'inTime', 'checkIn', 'firstIn',
                'outTime', 'checkOut', 'lastOut', 'breakMinutes', 'source', 'status', 'needsHrReview',
                'scheduledStart', 'scheduledIn', 'delayMinutes', 'zktecoEventCount', 'startAt', 'endAt', 'outDate']));
        }
        if (!$source && !$approval) return null;
        usort($source, fn($a, $b) => strcmp(R19Store::hash($a), R19Store::hash($b)));
        $fingerprint = R19Store::hash(['employeeId' => $employee['id'], 'date' => $date,
            'department' => $employee['department'], 'directManagerId' => $employee['directManagerId'] ?? '', 'records' => $source]);
        $issues = []; $intervals = []; $seen = []; $complete = (bool)$source;
        $firstIn = null; $lastOut = null; $delay = null; $crossesMidnight = false;
        if (!$source) $issues[] = 'لا يوجد سجل حضور صالح لهذا اليوم؛ لم تُحسب الموافقة القديمة.';
        foreach ($source as $record) {
            $in = self::minutes($record['inTime'] ?? $record['checkIn'] ?? $record['firstIn'] ?? null);
            $out = self::minutes($record['outTime'] ?? $record['checkOut'] ?? $record['lastOut'] ?? null);
            if ($in === null) {
                $complete = false; $issues[] = 'وقت الدخول ناقص أو غير صالح؛ راجع سجل الحضور.'; continue;
            }
            if ($firstIn === null || $in < $firstIn) {
                $firstIn = $in;
                $schedule = self::minutes($record['scheduledStart'] ?? $record['scheduledIn'] ?? null);
                $explicitDelay = $record['delayMinutes'] ?? null;
                $delay = is_numeric($explicitDelay) && $explicitDelay >= 0 && $explicitDelay <= 1440
                    ? (int)floor((float)$explicitDelay) : ($schedule === null ? null : max(0, $in - $schedule));
            }
            $start = $dayStart + $in * 60000;
            $closed = $out !== null;
            if ($closed) {
                $endMinutes = $out < $in ? $out + 1440 : $out;
                $crossesMidnight = $crossesMidnight || $endMinutes >= 1440;
                $end = $dayStart + $endMinutes * 60000;
                if ($lastOut === null || $endMinutes > $lastOut) $lastOut = $endMinutes;
                if ($end > $asOf) { $complete = false; $issues[] = 'وقت الخروج يقع في المستقبل؛ لا يمكن اعتماد الإضافي قبل انتهاء الحضور.'; }
            } else {
                $complete = false;
                // An unclosed earlier record is not assumed to end at midnight.
                $end = $asOf >= $dayStart && $asOf < $dayEnd ? $asOf : $start;
                $issues[] = 'سجل الدخول بلا خروج؛ المدّة مؤقتة ولا تعتمد كساعات إضافية.';
            }
            if (!empty($record['startAt'])) {
                try { $exactStart=(new \DateTimeImmutable($record['startAt']))->getTimestamp()*1000;
                    $exactEnd=!empty($record['endAt'])?(new \DateTimeImmutable($record['endAt']))->getTimestamp()*1000:null;
                    if($exactStart<$dayStart||$exactStart>=$dayEnd)throw new \RuntimeException('shift date');
                    $start=$exactStart;$closed=$exactEnd!==null;$end=$closed?$exactEnd:max($start,min($asOf,$start+86400000));
                    $endMinutes=(int)floor(($end-$dayStart)/60000);$crossesMidnight=$crossesMidnight||$endMinutes>=1440;
                    if($closed){$lastOut=max($lastOut??0,$endMinutes);if($end>$asOf){$complete=false;$issues[]='وقت الخروج يقع في المستقبل.';}}
                } catch (\Throwable $e) { $complete=false;$issues[]='طابع وقت حضور غير صالح.';continue; }
            }
            if (!empty($record['needsHrReview'])) {
                $complete = false; $issues[] = 'سجل البصمة يحتاج مراجعة الموارد البشرية قبل اعتماد الساعات.';
            }
            if ($start > $asOf || $end < $start || $end - $start > 86400000) {
                $complete = false; $issues[] = 'توجد أوقات حضور متعارضة؛ لم نخمن المدّة.'; continue;
            }
            $break = $record['breakMinutes'] ?? 0;
            if (!is_numeric($break) || $break < 0 || $break > 1440 || (float)$break > ($end - $start) / 60000) {
                $complete = false; $issues[] = 'مدة الاستراحة غير صالحة مقارنة بمدة الحضور.'; continue;
            }
            $stop = max($start, min($end, $asOf));
            $key = $start.'|'.$stop.'|'.$break;
            if (isset($seen[$key])) continue; // Same materialized shift is counted once.
            $seen[$key] = true;
            $intervals[] = ['start' => $start, 'end' => $stop, 'break' => (float)$break];
        }
        usort($intervals, fn($a, $b) => $a['start'] <=> $b['start'] ?: $a['end'] <=> $b['end']);
        $gross = 0; $breaks = 0; $a = null; $b = null;
        foreach ($intervals as $interval) {
            $breaks += $interval['break'];
            if ($a === null) { $a = $interval['start']; $b = $interval['end']; continue; }
            if ($interval['start'] < $b) {
                $complete = false; $issues[] = 'توجد سجلات حضور متداخلة؛ تظهر مدّة موحدة مؤقتة وتلزم مراجعتها قبل الاعتماد.';
                $b = max($b, $interval['end']);
            } else {
                $gross += $b - $a; $a = $interval['start']; $b = $interval['end'];
            }
        }
        if ($a !== null) $gross += $b - $a;
        $total = (int)floor(max(0, $gross / 60000 - $breaks));
        $extra = max(0, $total - 480);
        $sourceChanged = $approval && !hash_equals((string)($approval['sourceFingerprint'] ?? ''), $fingerprint);
        $stage = $sourceChanged ? 'source_changed' : ($approval['stage'] ?? 'pending_manager');
        $manager = !$sourceChanged && in_array($stage, ['pending_hr', 'finalized'], true)
            ? min($extra, max(0, (int)($approval['managerMinutes'] ?? 0))) : 0;
        $final = !$sourceChanged && $stage === 'finalized' && $complete
            ? min($manager, max(0, (int)($approval['finalMinutes'] ?? 0))) : 0;
        if ($sourceChanged) $issues[] = 'تغير سجل الحضور أو المدير المباشر بعد المراجعة؛ يلزم اعتماد الساعات مجدداً.';
        return ['id' => self::id((string)$employee['id'], $date), 'employeeId' => (string)$employee['id'],
            'name' => (string)$employee['name'], 'department' => (string)$employee['department'], 'date' => $date,
            'inTime' => $firstIn === null ? '—' : self::clock($firstIn),
            'outTime' => !$complete || $lastOut === null ? '—' : self::clock($lastOut % 1440),
            'crossesMidnight' => $crossesMidnight, 'complete' => $complete, 'total' => $total,
            'delay' => $delay, 'delayKnown' => $delay !== null, 'basic' => min($total, 480), 'extra' => $extra,
            'managerApproved' => $manager, 'finalApproved' => $final, 'unapproved' => max(0, $extra - $manager),
            'overtimeStatus' => $stage, 'approvalHistory' => $approval['history'] ?? [],
            'returnedReason' => $approval['returnReason'] ?? '', '_version' => (int)($approval['_version'] ?? 0),
            'sourceFingerprint' => $fingerprint, 'sourceChanged' => (bool)$sourceChanged,
            'directManagerId' => (string)($employee['directManagerId'] ?? ''), 'issues' => array_values(array_unique($issues)),
            'source' => 'employee-attendance', 'attendanceSources' => array_values(array_unique(array_map(fn($r) => (string)($r['source'] ?? 'hr'), $source))),
            'asOf' => $asOf, 'timeZone' => $zone];
    }

    public static function contextual(User $actor, Employee $employee, array $row): array
    {
        $stage = '';
        if (R19HrAccess::directManager($actor, $employee) && $row['complete'] && $row['extra'] > 0 &&
            in_array($row['overtimeStatus'], ['pending_manager', 'returned_manager', 'source_changed'], true)) $stage = 'manager';
        if (R19HrAccess::hr($actor) && ($actor->root() || (string)$actor->employee_id !== (string)$employee->id) &&
            $row['complete'] && $row['extra'] > 0 && $row['overtimeStatus'] === 'pending_hr' && $row['managerApproved'] > 0) $stage = 'hr';
        $resubmit = (string)$actor->employee_id === (string)$employee->id && $row['overtimeStatus'] === 'returned_employee';
        if ($resubmit) $stage = 'employee';
        $row['canReviewOvertime'] = in_array($stage, ['manager', 'hr'], true);
        $row['canResubmit'] = $resubmit; $row['decisionStage'] = $stage;
        if (!$row['directManagerId'] && $row['extra'] > 0) $row['issues'][] = 'يجب ربط الموظف بمدير مباشر صالح قبل اعتماد الإضافي.';
        return $row;
    }

    public static function monthly(array $employee, string $month, array $days, array $policy, string $today): array
    {
        $configured = array_key_exists($month, $policy['workdaysByMonth'] ?? []);
        $requiredDays = $configured ? (int)$policy['workdaysByMonth'][$month] : null;
        $required = $configured ? $requiredDays * 480 : null;
        $sum = fn($key) => array_sum(array_column($days, $key));
        $basic = $sum('basic'); $approved = $sum('finalApproved');
        $deficit = $configured ? max(0, $required - $basic) : null;
        $compensation = $configured ? min($approved, $deficit) : null;
        $covered = count(array_filter($days, fn($r) => $r['complete']));
        $closed = $month < substr($today, 0, 7);
        return ['employeeId' => (string)$employee['id'], 'name' => (string)$employee['name'], 'department' => (string)$employee['department'],
            'recordDays' => count($days), 'coveredDays' => $covered, 'basic' => $basic,
            'required' => $required, 'requiredDays' => $requiredDays, 'raw' => $sum('extra'),
            'managerApproved' => $sum('managerApproved'), 'finalApproved' => $approved, 'deficit' => $deficit,
            'compensation' => $compensation, 'netExtra' => $configured ? max(0, $approved - $deficit) : null,
            'remainingDeficit' => $configured ? max(0, $deficit - $approved) : null,
            'complete' => $configured && $closed && $covered === count($days) && $covered >= $requiredDays,
            'pending' => max(0, $sum('extra') - $approved), 'total' => $sum('total'),
            'sourceChangedDays' => count(array_filter($days, fn($r) => $r['sourceChanged'])),
            'monthClosed' => $closed, 'payrollCreated' => false];
    }

    private static function minutes(mixed $value): ?int
    {
        if (!is_string($value) || !preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/D', $value, $match)) return null;
        return (int)$match[1] < 24 && (int)$match[2] < 60 ? (int)$match[1] * 60 + (int)$match[2] : null;
    }

    private static function clock(int $minutes): string { return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60); }
}

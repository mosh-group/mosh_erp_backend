<?php
namespace App\Support;

use App\Models\{AuditEvent, Employee, Task, User, WorkPresence};
use Illuminate\Support\Facades\DB;

/** Personal, read-only daily activity report. Session timestamps are generated
 * by the server; attendance and its completeness remain separate concepts. */
final class WorkdayReport
{
    public static function forUser(User $actor, string $date): ?array
    {
        if (!$actor->employee_id) return null;
        $employee = Employee::find((string)$actor->employee_id);
        if (!$employee) return null;
        $eid = (string)$employee->id;
        $name = (string)($employee->data['name'] ?? '');
        $zone = (string)config('app.timezone', 'Asia/Damascus');
        $asOf = now()->getTimestampMs();
        [$start, $end] = self::bounds($date, $zone);
        $allowName = $name !== '' && Employee::where('data->name', $name)->count() === 1;

        // One read transaction prevents a response mixing pre- and post-commit
        // versions of a task and its work-presence transition.
        return DB::transaction(function () use ($actor, $employee, $eid, $name, $zone, $date, $asOf, $start, $end, $allowName) {
            $tasks = [];
            // Personal historic sessions still count after reassignment. Only
            // the employee's own intervals are emitted, never another's data.
            $query = Task::where(function ($q) use ($eid, $name, $allowName) {
                $q->where('employee_id', $eid)->orWhere('data->workTiming->activeWorkerId', $eid);
                self::orHasOwnSessions($q, 'data', $eid, $name, $allowName);
            });
            foreach ($query->cursor() as $row) {
                $d = $row->document();
                if (!RecordAccess::readable($actor, 'tasks', $d)) $d['title'] = 'مهمة سابقة';
                $tasks[] = $d;
            }
            $deleted = AuditEvent::where('resource', 'tasks')->where('action', 'delete')
                ->where(function ($q) use ($eid, $name, $allowName) {
                    $q->where('before_data->assigneeId', $eid)->orWhere('before_data->workTiming->activeWorkerId', $eid);
                    self::orHasOwnSessions($q, 'before_data', $eid, $name, $allowName);
                });
            foreach ($deleted->cursor() as $row) {
                $d = $row->before_data;
                if (!is_array($d)) continue;
                $d['_deletedAt'] = $row->created_at->getTimestampMs();
                if (!RecordAccess::readable($actor, 'tasks', $d)) $d['title'] = 'مهمة سابقة';
                $tasks[] = $d;
            }
            $presence = WorkPresence::where('employee_id', $eid)->get()->map(fn($r) => $r->document())->all();
            // A valid <=24 h session may close on the following day. Fetch its
            // closing event as well; actual clipping happens in assemble().
            $audit = AuditEvent::where('resource', 'work-presence')
                ->where(function ($q) use ($eid) {
                    $q->where('before_data->employeeId', $eid)->orWhere('after_data->employeeId', $eid);
                })
                ->where('created_at', '>=', \Illuminate\Support\Carbon::createFromTimestampMs($start, $zone)->toDateTimeString())
                ->where('created_at', '<=', \Illuminate\Support\Carbon::createFromTimestampMs($end + TaskExecutionClock::MAX_SESSION_MS, $zone)->toDateTimeString())
                ->orderBy('created_at')->orderBy('id')->get()
                ->map(fn($e) => ['id' => $e->id, 'at' => $e->created_at->getTimestampMs(),
                    'before' => $e->before_data, 'after' => $e->after_data])->all();
            return self::assemble(
                ['id' => $eid, 'name' => $name, 'allowName' => $allowName], $date, $zone, $asOf,
                $employee->data['attendanceRecords'] ?? [], $tasks, $presence, $audit,
                WorkdayHistory::since($eid)
            );
        });
    }

    /** Pure assembly is deliberately separated for deterministic regression tests. */
    public static function assemble(array $employee, string $date, string $zone, int $asOf,
        array $attendance, array $tasks, array $presence, array $audit, ?int $trackingSince = null): array
    {
        [$dayStart, $dayEnd] = self::bounds($date, $zone);
        $cutoff = min($dayEnd, $asOf);
        $activities = []; $issues = []; $seen = [];
        $add = function (array $r) use (&$activities, &$issues, &$seen, $dayStart, $cutoff, $asOf): void {
            $a = TaskExecutionClock::epoch($r['startAt'] ?? null);
            $b = TaskExecutionClock::epoch($r['endAt'] ?? null);
            $open = ($r['open'] ?? false) === true;
            if ($open) $b = $asOf;
            if ($a === null || $b === null || $b < $a || $a > $asOf || $b > $asOf + 2000
                || $b - $a > TaskExecutionClock::MAX_SESSION_MS) {
                $issues[] = 'توجد جلسة بتوقيت ناقص أو متعارض أو تجاوزت ٢٤ ساعة؛ لم نخمن مدتها.';
                return;
            }
            if ($b <= $dayStart || $a >= $cutoff || $a === $b) return;
            // Same session can appear in the live task and a delete/restore audit.
            $key = implode('|', [$r['kind'], $r['taskId'] ?? '', $a, $b]);
            if (isset($seen[$key])) return;
            $seen[$key] = true;
            $r['startAt'] = $a; $r['endAt'] = min($b, $asOf); $r['open'] = $open;
            $activities[] = $r;
        };
        foreach ($tasks as $t) {
            if (!is_array($t)) continue;
            $w = is_array($t['workTiming'] ?? null) ? $t['workTiming'] : [];
            foreach ($w['sessions'] ?? [] as $index => $s) {
                if (!is_array($s) || !self::owns($s, $employee)) continue;
                $add(['id' => 'task:'.($t['id'] ?? '').':'.($s['id'] ?? $index), 'kind' => 'task',
                    'taskId' => (string)($t['id'] ?? ''), 'taskTitle' => $t['title'] ?? 'مهمة',
                    'startAt' => $s['startAt'] ?? null, 'endAt' => $s['endAt'] ?? null]);
            }
            if (($t['employeeState'] ?? '') === 'working' && ($t['status'] ?? 'in_progress') === 'in_progress'
                && self::owns(['workerId' => ($w['activeWorkerId'] ?? '') ?: ($t['assigneeId'] ?? ''),
                    'worker' => ($w['activeWorker'] ?? '') ?: ($t['assignee'] ?? '')], $employee)) {
                $add(['id' => 'task:'.($t['id'] ?? '').':active', 'kind' => 'task',
                    'taskId' => (string)($t['id'] ?? ''), 'taskTitle' => $t['title'] ?? 'مهمة',
                    'startAt' => $w['activeStartedAt'] ?? $w['active_started_at'] ?? null,
                    'endAt' => $t['_deletedAt'] ?? $asOf, 'open' => !isset($t['_deletedAt'])]);
            }
            foreach ($w['unmeasuredSessions'] ?? [] as $gap) {
                $at = TaskExecutionClock::epoch($gap['recordedAt'] ?? null);
                if ($at !== null && $at >= $dayStart && $at < $cutoff
                    && (string)($gap['workerId'] ?? '') === (string)$employee['id']) {
                    $issues[] = 'توجد فترة تنفيذ قديمة غير مقاسة محفوظة للمراجعة، ولم تدخل في مجموع اليوم.';
                }
            }
        }
        foreach ($audit as $event) {
            if (!is_array($event)) continue;
            $before = is_array($event['before'] ?? null) ? $event['before'] : [];
            $after = is_array($event['after'] ?? null) ? $event['after'] : [];
            if ((string)($before['employeeId'] ?? '') !== (string)$employee['id']) continue;
            $kind = self::kind($before); $next = self::kind($after);
            if ($kind === null) continue;
            $field = $kind === 'break' ? 'breakAt' : 'noWorkAt';
            if ($kind === $next && ($before[$field] ?? null) === ($after[$field] ?? null)) continue;
            $add(['id' => 'presence:'.($event['id'] ?? '').':closed', 'kind' => $kind,
                'startAt' => $before[$field] ?? null,
                'endAt' => TaskExecutionClock::epoch($after['updatedAt'] ?? null) ?? ($event['at'] ?? null)]);
        }
        $currentCount = 0;
        foreach ($presence as $p) {
            if (!is_array($p) || (string)($p['employeeId'] ?? '') !== (string)$employee['id']) continue;
            $kind = self::kind($p);
            if (!$kind) continue;
            $currentCount++;
            $add(['id' => 'presence:'.($p['id'] ?? '').':open', 'kind' => $kind,
                'startAt' => $p[$kind === 'break' ? 'breakAt' : 'noWorkAt'] ?? null,
                'endAt' => $asOf, 'open' => true]);
        }
        if ($currentCount > 1) $issues[] = 'يوجد أكثر من سجل حالة نشطة لهذا الموظف؛ يلزم مراجعته.';
        $issues = array_values(array_unique($issues));
        usort($activities, fn($a, $b) => $a['startAt'] <=> $b['startAt'] ?: strcmp($a['id'], $b['id']));
        $coverage = [];
        if (!$issues && $trackingSince !== null && $trackingSince < $cutoff) {
            $coverage[] = ['startAt' => max($trackingSince, $dayStart), 'endAt' => $cutoff];
        }
        return [
            'version' => 'workday-r8', 'employeeId' => (string)$employee['id'], 'date' => $date,
            'timeZone' => $zone, 'asOf' => $asOf, 'live' => $asOf >= $dayStart && $asOf < $dayEnd,
            'complete' => !$issues && $trackingSince !== null && $trackingSince <= $dayStart,
            'activityAvailable' => true, 'historyPartial' => $trackingSince === null || $trackingSince > $dayStart || (bool)$issues,
            'trackingSince' => $trackingSince, 'attendance' => array_values($attendance),
            'activities' => $activities, 'coverage' => $coverage, 'issues' => $issues,
        ];
    }

    public static function bounds(string $date, string $zone): array
    {
        $tz = new \DateTimeZone($zone);
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, $tz);
        if (!$d || $d->format('Y-m-d') !== $date) throw new \InvalidArgumentException('Invalid workday date');
        return [$d->getTimestamp() * 1000, $d->modify('+1 day')->getTimestamp() * 1000];
    }

    /** SQLite JSON array containment compares whole objects; session objects
     * contain more than workerId. Use field matching, with bound values. */
    private static function orHasOwnSessions($q, string $column, string $eid, string $name, bool $allowName): void
    {
        if (!in_array($column, ['data', 'before_data'], true)) throw new \InvalidArgumentException('Invalid session column');
        if (DB::connection()->getDriverName() === 'sqlite') {
            $object = "CASE WHEN wd_session.type = 'object' THEN wd_session.value ELSE '{}' END";
            $tests = ["json_extract($object, '$.workerId') = ?", "json_extract($object, '$.employeeId') = ?"];
            $bindings = [$eid, $eid];
            if ($allowName) {
                $tests[] = "json_extract($object, '$.worker') = ?";
                $tests[] = "json_extract($object, '$.employeeName') = ?";
                $bindings[] = $name; $bindings[] = $name;
            }
            $q->orWhereRaw("EXISTS (SELECT 1 FROM json_each($column, '$.workTiming.sessions') AS wd_session WHERE (".implode(' OR ', $tests).'))', $bindings);
            return;
        }
        $q->orWhereJsonContains($column.'->workTiming->sessions', ['workerId' => $eid])
            ->orWhereJsonContains($column.'->workTiming->sessions', ['employeeId' => $eid]);
        if ($allowName) $q->orWhereJsonContains($column.'->workTiming->sessions', ['worker' => $name])
            ->orWhereJsonContains($column.'->workTiming->sessions', ['employeeName' => $name]);
    }

    private static function kind(array $p): ?string
    {
        if (($p['mode'] ?? '') === 'break') return 'break';
        return ($p['mode'] ?? '') === 'active' && !empty($p['noWork']) ? 'no_work' : null;
    }

    private static function owns(array $session, array $employee): bool
    {
        $id = trim((string)($session['workerId'] ?? $session['employeeId'] ?? ''));
        if ($id !== '') return $id === (string)$employee['id'];
        return !empty($employee['allowName']) && trim((string)($employee['name'] ?? '')) !== ''
            && trim((string)($session['worker'] ?? $session['employeeName'] ?? '')) === trim((string)$employee['name']);
    }
}

<?php
namespace App\Support;

use App\Models\Setting;

/** Completeness marker only. Actual intervals remain in task sessions and the
 * existing transactional audit log. No attendance is manufactured. */
final class WorkdayHistory
{
    public static function key(string $employeeId): string
    {
        return '_workday_r8_'.hash('sha256', $employeeId);
    }

    public static function since(string $employeeId): ?int
    {
        $v = Setting::valueOf(self::key($employeeId));
        return (string)($v['employeeId'] ?? '') === $employeeId
            ? TaskExecutionClock::epoch($v['sinceAt'] ?? null) : null;
    }

    /** Called only inside the existing successful business transaction. */
    public static function observe(string $resource, ?array $old, ?array $new): void
    {
        if (!in_array($resource, ['tasks', 'work-presence'], true)) return;
        $fields = $resource === 'tasks'
            ? ['employeeState', 'status', 'assigneeId', 'workTiming'] : ['mode', 'noWork'];
        $changed = !$old || !$new;
        foreach ($fields as $key) $changed = $changed || (($old[$key] ?? null) !== ($new[$key] ?? null));
        if (!$changed) return;
        $ids = [];
        foreach ([$old, $new] as $d) {
            if (!$d) continue;
            $id = (string)($resource === 'tasks' ? ($d['assigneeId'] ?? '') : ($d['employeeId'] ?? ''));
            if ($id !== '') $ids[$id] = true;
        }
        foreach (array_keys($ids) as $id) {
            Setting::firstOrCreate(['id' => self::key($id)], [
                'value' => ['employeeId' => $id, 'sinceAt' => now()->getTimestampMs(), 'source' => 'workday-r8'],
                'version' => 1,
            ]);
        }
    }
}

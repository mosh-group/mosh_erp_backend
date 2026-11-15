<?php
namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Validator;

final class R19TaskPriority
{
    public static function canManage(User $actor, array $task): bool
    {
        return $actor->active && ($actor->root() || $actor->administration() ||
            (!empty($task['department']) && $actor->manages((string)$task['department'])));
    }

    public static function urgent(array $task): bool
    {
        if (array_key_exists('isUrgent', $task)) return (bool)$task['isUrgent'];
        if (array_key_exists('urgent', $task)) return (bool)$task['urgent'];
        return ($task['priority'] ?? '') === 'urgent';
    }

    public static function apply(User $actor, ?array $old, array &$next): void
    {
        Validator::make($next, ['isUrgent' => 'sometimes|boolean', 'urgent' => 'sometimes|boolean', 'priority' => 'sometimes|in:normal,urgent'])->validate();
        $requested = [];
        foreach (['isUrgent', 'urgent', 'priority'] as $field) {
            if (!array_key_exists($field, $next) || ($old && array_key_exists($field, $old) && $next[$field] === $old[$field])) continue;
            $requested[] = $field === 'priority' ? $next[$field] === 'urgent' : (bool)$next[$field];
        }
        $requested = array_values(array_unique($requested, SORT_REGULAR));
        abort_if(count($requested) > 1, 422, 'قيم أولوية المهمة متعارضة.');
        Validator::make($next,['urgentReason'=>'sometimes|nullable|string|max:2000'])->validate();
        $before = self::urgent($old ?? []);
        $after = $requested[0] ?? $before;
        if ($after !== $before || (!$old && $after) || (($next['urgentReason']??'')!==($old['urgentReason']??''))) {
            if($after) abort_unless(trim((string)($next['urgentReason']??''))!=='',422,'سبب الاستعجال إلزامي.');
            abort_unless(self::canManage($actor, $old ?? $next) && self::canManage($actor, $next), 403, 'تحديد الاستعجال متاح للإدارة ومدير قسم المهمة فقط.');
            $next['urgentUpdatedAt'] = now()->toIso8601String();
            $next['urgentUpdatedBy'] = (string)$actor->id;
            $next['urgentUpdatedByName'] = (string)$actor->name;
        } else {
            foreach (['urgentUpdatedAt', 'urgentUpdatedBy', 'urgentUpdatedByName'] as $field) {
                if (array_key_exists($field, $old ?? [])) $next[$field] = $old[$field];
                else unset($next[$field]);
            }
        }
        if ($requested || array_key_exists('isUrgent', $old ?? [])) {
            $next['isUrgent'] = $next['urgent'] = $after;
            $next['priority'] = $after ? 'urgent' : 'normal';
        }
    }
}

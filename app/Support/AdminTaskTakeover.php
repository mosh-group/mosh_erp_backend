<?php

namespace App\Support;

use App\Models\{Employee, Task, User};
use App\Services\R34\CommandContext;
use Illuminate\Support\Facades\Validator;

final class AdminTaskTakeover
{
    private const CONTEXT = 'r24-admin-task-takeover';

    public static function execute(User $actor, array $input): array
    {
        self::authorize($actor);
        $values = Validator::make($input, [
            'taskId' => ['required', 'string', 'max:190'],
            '_version' => ['required', 'integer', 'min:1'],
            'requestId' => ['required', 'string', 'min:8', 'max:128', 'regex:/^[A-Za-z0-9_.:-]+$/D'],
        ])->validate();

        $receipt = R19Store::commit($actor, self::CONTEXT.':'.$values['taskId'], $values,
            function (string $transaction) use ($actor, $values) {
                self::authorize($actor);
                $owner = Employee::whereKey((string) $actor->employee_id)->lockForUpdate()->first();
                abort_unless($owner && R19HrAccess::activeEmployee($owner) && empty($owner->data['archivedAt']), 422,
                    'اربط الحساب الأساسي بملف موظف نشط قبل استلام تنفيذ المهمة.');

                $task = Task::whereKey($values['taskId'])->lockForUpdate()->firstOrFail();
                $record = $task->document();
                abort_if(!empty($record['deletedAt']), 404, 'المهمة غير موجودة.');
                abort_unless($task->version === (int) $values['_version'], 409, 'تغيّرت المهمة. حدّث التفاصيل قبل استلام التنفيذ.');
                abort_unless(self::eligible($record, (string) $owner->id), 422,
                    'استلام التنفيذ متاح فقط لمهمة مفتوحة ومسندة إلى موظف آخر ولم تُرسل للمراجعة.');

                $history = $record['history'] ?? [];
                $history[] = [
                    'type' => 'admin_task_takeover',
                    'text' => 'استلم '.$actor->name.' تنفيذ المهمة من '.(($record['assignee'] ?? '') ?: 'الموظف المكلّف').'.',
                    'fromEmployeeId' => (string) $record['assigneeId'],
                    'toEmployeeId' => (string) $owner->id,
                ];
                $change = CommandContext::run(self::CONTEXT, (string) $record['id'], fn () =>
                    Records::change($actor, [
                        'resource' => 'tasks',
                        'id' => (string) $task->id,
                        'action' => 'update',
                        'version' => $task->version,
                        'data' => [
                            'assigneeId' => (string) $owner->id,
                            'assignmentMode' => 'direct',
                            'employeeState' => 'new',
                            'reviewManager' => '',
                            'returnedForRevision' => false,
                            'history' => $history,
                        ],
                    ], $transaction)
                );
                $changes = [$change];
                BusinessRules::afterTransaction($actor, $changes, $transaction);

                return ['id' => (string) $task->id];
            });

        self::authorize($actor);
        $record = Task::findOrFail($receipt['id'])->document();
        abort_if(!empty($record['deletedAt']), 404, 'المهمة غير موجودة.');

        return RecordAccess::document($actor, 'tasks', $record);
    }

    public static function permitsAssignment(User $actor, ?array $previous, array $task): bool
    {
        if (!$previous || !CommandContext::permits(self::CONTEXT, (string) ($task['id'] ?? ''))) {
            return false;
        }

        $ownerId = (string) $actor->employee_id;

        return $actor->active && $actor->root() && !$actor->must_change
            && $ownerId !== '' && $ownerId === (string) ($task['assigneeId'] ?? '')
            && ($previous['id'] ?? null) === ($task['id'] ?? null)
            && ($previous['department'] ?? null) === ($task['department'] ?? null)
            && ($task['status'] ?? '') === 'in_progress'
            && ($task['employeeState'] ?? '') === 'new'
            && self::eligible($previous, $ownerId);
    }

    private static function authorize(User $actor): void
    {
        abort_unless($actor->active && $actor->root() && !$actor->must_change, 403);
    }

    private static function eligible(array $task, string $ownerId): bool
    {
        $assignee = (string) ($task['assigneeId'] ?? '');

        return ($task['status'] ?? '') === 'in_progress'
            && in_array($task['employeeState'] ?? 'new', ['new', 'received', 'working', 'paused'], true)
            && $assignee !== '' && $assignee !== $ownerId
            && empty($task['deletedAt']) && empty($task['sharedDistributionRoot']) && empty($task['sharedTeamContainer'])
            && ($task['sharedNodeType'] ?? '') !== 'team';
    }
}


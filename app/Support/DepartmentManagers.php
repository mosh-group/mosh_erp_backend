<?php
namespace App\Support;

use App\Models\{Employee, Role, Setting, Task, User};

/** Single source of truth: a department->employee map. Employees keep their
 * original employment department. Several keys may reference the same ID. */
final class DepartmentManagers
{
    public static function normalize(mixed $input): array
    {
        $out = [];
        foreach (is_array($input) || is_object($input) ? $input : [] as $department => $id) {
            if (is_string($department) && is_scalar($id) && trim((string)$id) !== '') $out[$department] = (string)$id;
        }
        return $out;
    }
    public static function replace(array $map, string $department, string $managerId, string $expectedId): array
    {
        $map = self::normalize($map);
        if (($map[$department] ?? '') !== $expectedId) throw new \DomainException('تغيّر مدير هذا القسم؛ حدّث البيانات قبل إعادة الحفظ.');
        if ($managerId === '') unset($map[$department]); else $map[$department] = $managerId;
        return $map;
    }
    private const BACKUPS = '_hr_department_manager_backups';

    public static function coverage(): array
    {
        return Setting::memoizedRead('@department-manager-coverage', function () {
            $primary = self::normalize(Setting::valueOf('department-managers'));
            $backups = self::normalize(Setting::valueOf(self::BACKUPS));
            $effective = $primary;
            $acting = [];
            if (!$backups) return compact('primary', 'backups', 'effective', 'acting');

            $ids = array_values(array_unique(array_merge(array_values($backups), array_values(array_intersect_key($primary, $backups)))));
            $employees = Employee::whereIn('id', $ids)->get(['id', 'data'])->keyBy('id');
            $accounts = User::whereIn('employee_id', array_values($backups))->where('active', true)
                ->get(['employee_id', 'must_change'])->groupBy('employee_id');
            $now = now(config('app.timezone'));
            foreach ($backups as $department => $backupId) {
                $id = $primary[$department] ?? '';
                $manager = $employees->get($id);
                if ($id === $backupId || !self::eligibleEmployee($manager) || !self::onApprovedLeave($manager, $now)) continue;

                $backup = $employees->get($backupId);
                $linked = $accounts->get($backupId);
                if (!self::eligibleEmployee($backup) || $linked?->count() !== 1 || $linked->first()->must_change || self::onApprovedLeave($backup, $now)) continue;

                $effective[$department] = $acting[$department] = $backupId;
            }
            return compact('primary', 'backups', 'effective', 'acting');
        });
    }

    private static function eligibleEmployee(?Employee $employee): bool
    {
        return $employee && empty($employee->data['archivedAt']) && R19HrAccess::activeEmployee($employee);
    }

    private static function onApprovedLeave(Employee $employee, \DateTimeInterface $now): bool
    {
        $day = $now->format('Y-m-d');
        $time = $now->format('H:i');
        foreach ($employee->data['leaveRecords'] ?? [] as $leave) {
            if (($leave['status'] ?? '') !== 'approved' || empty($leave['startDate']) || ($leave['startDate'] > $day) || ($leave['endDate'] ?? '') < $day) continue;
            if (in_array($leave['type'] ?? '', ['ساعية', 'hourly', 'hourly_leave'], true)) {
                if (empty($leave['fromTime']) || empty($leave['toTime']) || $time < $leave['fromTime'] || $time >= $leave['toTime']) continue;
            }
            return true;
        }
        return false;
    }

    public static function effective(): array
    {
        return self::coverage()['effective'];
    }

    public static function actingDepartments(User $user): array
    {
        if (!$user->active || $user->must_change || !$user->employee_id) return [];
        return array_keys(self::coverage()['acting'], (string)$user->employee_id, true);
    }

    public static function actions(): array
    {
        return Setting::memoizedRead('@acting-manager-actions', fn () => Role::find('role_manager')?->data['scopeActions'] ?? []);
    }

    public static function view(): array
    {
        $coverage = self::coverage();
        return [
            'backups'=>(object)$coverage['backups'], 'effective'=>(object)$coverage['effective'],
            'acting'=>(object)$coverage['acting'], 'actions'=>(object)($coverage['acting'] ? self::actions() : []),
        ];
    }

    public static function assignBackup(User $actor, array $input): array
    {
        abort_unless($actor->active && !$actor->must_change && RecordAccess::settings($actor, 'department-managers'), 403);
        R19Store::commit($actor, 'department-manager-backup', $input, function ($tx) use ($actor, $input) {
            abort_unless(RecordAccess::settings($actor, 'department-managers'), 403);
            $department = $input['department'];
            $id = $input['managerId'];
            abort_unless($department !== 'الإدارة' && in_array($department, BusinessRules::departments(), true), 422, 'القسم غير موجود ضمن مدراء الأقسام.');
            if ($id !== '') {
                $employee = Employee::find($id);
                abort_unless($employee && empty($employee->data['archivedAt']) && R19HrAccess::activeEmployee($employee), 422, 'اختر موظفاً نشطاً.');
                abort_if((self::normalize(Setting::valueOf('department-managers'))[$department] ?? '') === $id, 422, 'اختر مديراً بديلاً مختلفاً عن المدير الأساسي.');
            }
            $old = R19Store::get(self::BACKUPS);
            $before = self::normalize(array_diff_key($old ?? [], ['_version'=>true]));
            try { $next = self::replace($before, $department, $id, $input['expectedManagerId']); }
            catch (\DomainException $e) { abort(409, $e->getMessage()); }
            if ($next !== $before) {
                R19Store::put(self::BACKUPS, $next, $old['_version'] ?? 0);
                Audit::record($actor, 'hr', 'department-manager-backups', $department, 'assign', $before, $next, $tx);
            }
            return ['department'=>$department];
        });
        abort_unless(RecordAccess::settings($actor, 'department-managers'), 403);
        return self::view();
    }

    public static function assign(User $actor, array $input): array
    {
        abort_unless($actor->active && !$actor->must_change && RecordAccess::settings($actor, 'department-managers'), 403);
        $result = R19Store::commit($actor, 'department-manager', $input, function ($tx) use ($actor, $input) {
            $actor->unsetRelation('role');
            abort_unless(RecordAccess::settings($actor, 'department-managers'), 403, 'تعيين المدراء محصور بصلاحية تعديل الموارد البشرية.');
            $id = $input['managerId'];
            $targets = $input['departments'] ?? [['department' => $input['department'], 'expectedManagerId' => $input['expectedManagerId']]];
            $validDepartments = BusinessRules::departments();
            $manager = $id === '' ? null : Employee::find($id);
            abort_unless($id === '' || ($manager && R19HrAccess::activeEmployee($manager)), 422, 'اختر موظفاً نشطاً.');
            $setting = Setting::findOrFail('department-managers'); $before = self::normalize($setting->value);
            $map = $before;
            $updatedDepartments = [];
            foreach ($targets as $target) {
                $department = $target['department'];
                abort_unless(in_array($department, $validDepartments, true), 422, 'القسم غير موجود.');
                try { $map = self::replace($map, $department, $id, $target['expectedManagerId']); }
                catch (\DomainException $e) { abort(409, $e->getMessage()); }
                if (($before[$department] ?? '') !== $id) $updatedDepartments[] = $department;
            }
            $changes = [];
            if ($map !== $before) {
                $setting->update(['value' => $map, 'version' => $setting->version + 1]);
                foreach ($updatedDepartments as $department) {
                    Audit::record($actor, 'hr', 'settings/department-managers', $department, 'assign', $before, $map, $tx);
                }
                foreach (Employee::all() as $employee) {
                    $old = $employee->document(); $data = $employee->data;
                    $data['isManager'] = R19HrAccess::activeEmployee($employee) && in_array((string)$employee->id, array_values($map), true);
                    if (in_array($data['department'] ?? '', $updatedDepartments, true)) {
                        $data['directManager'] = (string)$employee->id === $id ? '' : ($manager?->data['name'] ?? '');
                    }
                    if ($data === $employee->data) continue;
                    $data['updatedAt'] = now()->toIso8601String(); $data['updatedBy'] = $actor->name;
                    $employee->update(['data' => $data, 'version' => $employee->version + 1]);
                    Audit::record($actor, 'hr', 'employees', (string)$employee->id, 'hierarchy', $old, $employee->document(), $tx);
                    $changes[] = ['resource' => 'employees', 'id' => (string)$employee->id, 'data' => RecordAccess::document($actor, 'employees', $employee->document())];
                }
                foreach (Task::whereIn('department', $updatedDepartments)->get() as $task) {
                    $data = $task->data;
                    if (($data['status'] ?? '') !== 'in_progress' || ($data['employeeState'] ?? '') !== 'submitted' || ($data['assignmentMode'] ?? '') === 'manager_self') continue;
                    $name = $manager?->data['name'] ?? '';
                    if (($data['reviewManager'] ?? '') === $name) continue;
                    $old = $task->document(); $data['reviewManager'] = $name;
                    $task->update(['data' => $data, 'version' => $task->version + 1]);
                    Audit::record($actor, 'hr', 'tasks', (string)$task->id, 'review-manager', $old, $task->document(), $tx);
                    if (RecordAccess::readable($actor, 'tasks', $task->document())) $changes[] = ['resource'=>'tasks', 'id'=>(string)$task->id, 'data'=>$task->document()];
                }
            }
            $changes[] = ['resource'=>'settings/department-managers', 'id'=>'department-managers', 'data'=>['value'=>(object)$map, '_version'=>$setting->version]];
            $selection = isset($input['departments'])
                ? ['departments' => array_column($targets, 'department')]
                : ['department' => $input['department']];
            return $selection + ['managerId' => $id, 'changes' => $changes];
        });
        $actor->refresh(); $actor->unsetRelation('role');
        abort_unless($actor->active && !$actor->must_change && RecordAccess::settings($actor, 'department-managers'), 403);
        $changes = [];
        foreach ($result['changes'] ?? [] as $change) {
            if ($change['resource'] === 'settings/department-managers') {
                $s = Setting::findOrFail('department-managers');
                $change['data'] = ['value'=>(object)self::normalize($s->value), '_version'=>$s->version];
            } else {
                $class = $change['resource'] === 'employees' ? Employee::class : Task::class;
                $row = $class::find($change['id']);
                if (!$row || !RecordAccess::readable($actor, $change['resource'], $row->document())) continue;
                $change['data'] = RecordAccess::document($actor, $change['resource'], $row->document());
            }
            $changes[] = $change;
        }
        $result['changes'] = $changes;
        return $result + ['coverage'=>self::view()];
    }
}

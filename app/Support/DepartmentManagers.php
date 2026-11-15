<?php
namespace App\Support;

use App\Models\{Employee, Setting, Task, User};

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
    public static function assign(User $actor, array $input): array
    {
        abort_unless($actor->active && !$actor->must_change && RecordAccess::settings($actor, 'department-managers'), 403);
        $result = R19Store::commit($actor, 'department-manager', $input, function ($tx) use ($actor, $input) {
            $actor->unsetRelation('role');
            abort_unless(RecordAccess::settings($actor, 'department-managers'), 403, 'تعيين المدراء محصور بصلاحية تعديل الموارد البشرية.');
            $department = $input['department']; $id = $input['managerId'];
            abort_unless(in_array($department, BusinessRules::departments(), true), 422, 'القسم غير موجود.');
            $manager = $id === '' ? null : Employee::find($id);
            abort_unless($id === '' || ($manager && R19HrAccess::activeEmployee($manager)), 422, 'اختر موظفاً نشطاً.');
            $setting = Setting::findOrFail('department-managers'); $before = self::normalize($setting->value);
            try { $map = self::replace($before, $department, $id, $input['expectedManagerId']); }
            catch (\DomainException $e) { abort(409, $e->getMessage()); }
            $changes = [];
            if ($map !== $before) {
                $setting->update(['value' => $map, 'version' => $setting->version + 1]);
                Audit::record($actor, 'hr', 'settings/department-managers', $department, 'assign', $before, $map, $tx);
                foreach (Employee::all() as $employee) {
                    $old = $employee->document(); $data = $employee->data;
                    $data['isManager'] = R19HrAccess::activeEmployee($employee) && in_array((string)$employee->id, array_values($map), true);
                    if (($data['department'] ?? '') === $department) $data['directManager'] = (string)$employee->id === $id ? '' : ($manager?->data['name'] ?? '');
                    if ($data === $employee->data) continue;
                    $data['updatedAt'] = now()->toIso8601String(); $data['updatedBy'] = $actor->name;
                    $employee->update(['data' => $data, 'version' => $employee->version + 1]);
                    Audit::record($actor, 'hr', 'employees', (string)$employee->id, 'hierarchy', $old, $employee->document(), $tx);
                    $changes[] = ['resource' => 'employees', 'id' => (string)$employee->id, 'data' => RecordAccess::document($actor, 'employees', $employee->document())];
                }
                foreach (Task::where('department', $department)->get() as $task) {
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
            return ['department'=>$department, 'managerId'=>$id, 'changes'=>$changes];
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
        return $result;
    }
}

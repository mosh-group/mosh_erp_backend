<?php
namespace App\Support;

use App\Models\{Employee, Setting, User};

final class R19HrAccess
{
    public static function hr(User $actor, string $action = 'approve'): bool
    {
        // An administration role with scope=all is not the HR decision stage.
        return $actor->active && ($actor->root() ||
            (User::departmentApp((string)$actor->department) === 'hr' && $actor->allows('hr', $action)));
    }

    public static function admin(User $actor): bool { return $actor->active && $actor->administration(); }

    public static function managedDepartments(User $actor): array
    {
        if (!$actor->active) return [];
        if ($actor->root()) return BusinessRules::departments();
        if (!$actor->employee_id) return [];
        return array_keys(array_filter(DepartmentManagers::effective(), fn($id) => is_scalar($id) && (string)$id === (string)$actor->employee_id));
    }

    public static function directManagerId(Employee $employee): string
    {
        $configured=\App\Services\R34\Hierarchy::managerId($employee);
        if ($configured !== null) {
            $department = $employee->data['department'] ?? '';
            $coverage = DepartmentManagers::coverage();
            $manager = ($coverage['primary'][$department] ?? null) === $configured
                ? ($coverage['acting'][$department] ?? $configured) : $configured;
            return $manager === (string)$employee->id ? '' : $manager;
        }
        $raw = $employee->data['directManager'] ?? '';
        $raw = is_string($raw) ? trim($raw) : '';
        if ($raw !== '') {
            $candidate = Employee::find($raw);
            if (!$candidate) {
                $matches = Employee::where('data->name', $raw)->get();
                if ($matches->count() === 1) $candidate = $matches->first();
            }
            $department = $employee->data['department'] ?? '';
            $coverage = DepartmentManagers::coverage();
            if ($candidate && (string)$candidate->id === ($coverage['primary'][$department] ?? null) && isset($coverage['acting'][$department])) {
                $replacement = $coverage['acting'][$department];
                return $replacement !== (string)$employee->id ? $replacement : '';
            }
            return $candidate && self::activeEmployee($candidate) && (string)$candidate->id !== (string)$employee->id ? (string)$candidate->id : '';
        }
        $id = DepartmentManagers::effective()[$employee->data['department'] ?? ''] ?? '';
        $candidate = is_scalar($id) && (string)$id !== '' ? Employee::find((string)$id) : null;
        return $candidate && self::activeEmployee($candidate) && (string)$candidate->id !== (string)$employee->id ? (string)$candidate->id : '';
    }

    public static function activeEmployee(Employee $employee): bool
    {
        return ($employee->data['active'] ?? true) !== false && ($employee->data['employmentStatus'] ?? 'active') === 'active';
    }

    public static function directManager(User $actor, Employee $employee): bool
    {
        return $actor->active && ($actor->root() || ($actor->employee_id && (string)$actor->employee_id === self::directManagerId($employee)));
    }

    public static function request(User $actor, ?Employee $employee = null): bool
    {
        if (!$actor->active) return false;
        if (!$employee) return count(self::managedDepartments($actor)) > 0;
        return self::activeEmployee($employee) && (string)$actor->employee_id !== (string)$employee->id &&
            ($actor->root() || in_array((string)($employee->data['department'] ?? ''), self::managedDepartments($actor), true));
    }

    public static function attendance(User $actor, Employee $employee): bool
    {
        if (!$actor->active) return false;
        return self::admin($actor) || self::hr($actor, 'reports') || self::hr($actor, 'details') ||
            (string)$actor->employee_id === (string)$employee->id || self::directManager($actor, $employee) ||
            in_array((string)($employee->data['department'] ?? ''), self::managedDepartments($actor), true);
    }

    public static function discipline(User $actor, array $row): bool
    {
        if (!$actor->active) return false;
        if (self::admin($actor) || self::hr($actor, 'details') || self::hr($actor)) return true;
        if (in_array((string)($row['department'] ?? ''), self::managedDepartments($actor), true)) return true;
        return (string)$actor->employee_id === (string)($row['employeeId'] ?? '') && ($row['status'] ?? '') === 'approved';
    }

    public static function disciplineStage(User $actor, array $row): string
    {
        if (!self::discipline($actor, $row)) return '';
        // A subject cannot approve their own disciplinary case.
        if (!$actor->root() && (string)$actor->employee_id === (string)($row['employeeId'] ?? '')) return '';
        if (($row['status'] ?? '') === 'pending_admin' && self::admin($actor)) return 'admin';
        if (($row['status'] ?? '') === 'pending_hr' && self::hr($actor)) return 'hr';
        return '';
    }

    public static function role(User $actor): string
    {
        if (self::hr($actor) && !$actor->root()) return 'hr';
        if (self::admin($actor)) return 'admin';
        return self::managedDepartments($actor) ? 'manager' : 'employee';
    }

    public static function capabilities(User $actor): array
    {
        return ['requestDisciplinary' => self::request($actor), 'reviewAdministration' => self::admin($actor),
            'reviewHr' => self::hr($actor), 'editPolicy' => self::hr($actor, 'edit'),
            'managedDepartments' => self::managedDepartments($actor)];
    }
}

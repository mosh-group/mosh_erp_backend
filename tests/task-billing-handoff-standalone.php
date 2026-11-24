<?php
// Isolated BusinessRules regression: no Laravel boot, database, HTTP, or telemetry.
namespace {
    function now(): object {
        return new class {
            public function toIso8601String(): string { return '2026-11-23T10:00:00+03:00'; }
        };
    }
    function abort_unless($condition, $status, $message = ''): void {
        if (!$condition) throw new \RuntimeException($message ?: 'Forbidden', $status);
    }
}
namespace App\Models {
    class User {
        public string $employee_id = 'MANAGER';
        public string $name = 'Department manager';
        public function __construct(public bool $approve = true, string $employeeId = 'MANAGER') {
            $this->employee_id = $employeeId;
        }
        public function allows($module, $action = 'view'): bool { return in_array($action, ['status', 'assign'], true) || ($action === 'approve' && $this->approve); }
        public function manages($department): bool { return (Setting::$managers[$department] ?? '') === $this->employee_id; }
        public function root(): bool { return false; }
    }
    class Employee {
        public static array $rows = [];
        public static function find($id): ?object { return self::$rows[(string)$id] ?? null; }
    }
    class Setting {
        public static array $managers = [];
        public static function valueOf($key): array { return self::$managers; }
    }
    class Task {
        public static bool $hasChildren = false;
        public static function where(...$args): self { return new self; }
        public static function find($id): ?self { return null; }
        public static int $finishedChildren = 0;
        public function count(): int { return self::$hasChildren ? 2 : self::$finishedChildren; }
        public function whereNotIn(...$args): self { return $this; }
        public static bool $hasUnfinishedChildren = false;
        public function exists(): bool { return self::$hasUnfinishedChildren; }
    }
}
namespace App\Services\R34 {
    class TaskPolicy { public static function apply(...$args): void {} }
}
namespace App\Support {
    class R19TaskPriority { public static function apply(...$args): void {} }
    class TaskExecutionClock { public static function apply(...$args): void {} }
    class RecordAccess { public static function module($resource, $data): string { return 'source-department'; } }
}
namespace {
    require __DIR__.'/../app/Support/TaskBillingHandoff.php';
    require __DIR__.'/../app/Support/BusinessRules.php';
    $checks = 0;
    function check($ok, $message): void {
        global $checks; if (!$ok) throw new \RuntimeException($message); $checks++;
    }
    $rule = new \ReflectionMethod(\App\Support\BusinessRules::class, 'task');
    function validateTask(array $old, array $next, bool $approve = true, string $employeeId = 'MANAGER'): array {
        global $rule; $actor = new \App\Models\User($approve, $employeeId);
        $rule->invokeArgs(null, [$actor, $old, &$next]); return $next;
    }
    function reject(array $old, array $next, int $status, bool $approve = true): void {
        try { validateTask($old, $next, $approve); } catch (\RuntimeException $e) {
            check($e->getCode() === $status, 'unexpected rejection: '.$e->getMessage()); return;
        }
        throw new \RuntimeException('unsafe handoff was accepted');
    }
    $departments = ['الإدارة','الموارد البشرية','المبيعات','التسويق','السوشال ميديا','التصميم','التصوير','المونتاج','الترويج','تكنولوجيا المعلومات','اللوجستيات','المشتريات','المحاسبة','المستودعات العامة','MOSH MEDIA'];
    foreach ($departments as $department) {
        \App\Models\Employee::$rows = ['E1'=>(object)['id'=>'E1','data'=>['name'=>'Original worker','department'=>$department,'active'=>true]]];
        $old = ['id'=>'T1','department'=>$department,'assigneeId'=>'E1','assignee'=>'Original worker','status'=>'in_progress','employeeState'=>'submitted','history'=>[],'workChat'=>[]];
        $next = array_replace($old, ['department'=>'المحاسبة','status'=>'awaiting_invoice','employeeState'=>'finished','closedFromDepartment'=>$department]);
        $approved = validateTask($old, $next);
        if ($department === 'المحاسبة') {
            check($approved === $next, 'accounting may finish billing work');
        } else {
            check($approved['department'] === 'المهمات' && $approved['status'] === 'in_progress'
                && $approved['employeeState'] === 'finished' && $approved['followUpFromDepartment'] === $department
                && $approved['assigneeId'] === '' && !isset($approved['closedAt']), 'approved task goes to follow-up: '.$department);
            $invoice = array_replace($approved,['department'=>'المحاسبة','status'=>'awaiting_invoice','closedFromDepartment'=>'المهمات']);
            check(validateTask($approved, $invoice) === $invoice, 'follow-up sends task to accounting: '.$department);
        }
        reject($old, $next, 403, false);
    }
    // Exercise cross-department cases separately from the accounting-to-accounting fixture.
    $old['department'] = 'التسويق';$next['closedFromDepartment'] = 'التسويق';
    \App\Models\Employee::$rows['E1']->data['department'] = 'التسويق';
    \App\Models\Employee::$rows['E2'] = (object)['id'=>'E2','data'=>['department'=>'التصميم','active'=>true]];
    \App\Models\Task::$finishedChildren = 1;
    check(validateTask($old,$next)['department'] === 'المهمات', 'single finished child permits follow-up');
    \App\Models\Task::$finishedChildren = 0;
    reject($old, array_replace($next, ['assigneeId'=>'E2','assignee'=>'Different worker']), 422);
    reject($old, array_replace($next, ['assignee'=>'Forged name']), 422);
    reject($old, array_replace($next, ['department'=>'التصميم']), 422);
    reject($old, array_replace($next, ['closedFromDepartment'=>'التصميم']), 422);
    reject($old, array_replace($next, ['employeeState'=>'working']), 422);
    reject(array_replace($old, ['employeeState'=>'working']), $next, 422);
    reject($old, array_replace($old, ['department'=>'التصميم','employeeState'=>'new']), 422);
    check(!\App\Support\TaskBillingHandoff::preservesExecutor(null, $next), 'new records cannot use a historical executor');
    check(validateTask($next, $next) === $next, 'later billing saves keep the confirmed executor');
    foreach (['invoiced','archived_invoiced','archived_no_invoice'] as $status) {
        check(\App\Support\TaskBillingHandoff::preservesExecutor($next, array_replace($next, ['status'=>$status])), 'billing history retains executor: '.$status);
    }
    check(!\App\Support\TaskBillingHandoff::preservesExecutor($next, array_replace($next, ['closedFromDepartment'=>'التصميم'])), 'billing history cannot forge source department');
    $department = 'التسويق';
    \App\Models\Setting::$managers = [$department => 'MANAGER'];
    \App\Models\Employee::$rows['MANAGER'] = (object)['id'=>'MANAGER', 'data'=>['name'=>'Department manager', 'department'=>'السوشال ميديا']];
    $working = array_replace($old, ['assigneeId'=>'MANAGER', 'assignee'=>'Department manager', 'employeeState'=>'working', 'assignmentMode'=>'direct']);
    $submitted = array_replace($working, ['employeeState'=>'submitted', 'reviewManager'=>'Department manager']);
    $finished = validateTask($working, $submitted, false);
    check($finished['employeeState'] === 'finished' && $finished['status'] === 'in_progress'
        && $finished['department'] === 'المهمات' && $finished['followUpFromDepartment'] === $department && !isset($finished['closedFromDepartment'])
        && $finished['assigneeId'] === '' && $finished['reviewManager'] === '', 'manager finishing their own task sends it to follow-up');
    $managerBilling = array_replace($finished, ['department'=>'المحاسبة','status'=>'awaiting_invoice','closedFromDepartment'=>'المهمات']);
    check(validateTask($finished, $managerBilling, true, 'FOLLOW-UP') === $managerBilling, 'follow-up sends the manager task to billing');
    \App\Models\Task::$finishedChildren = 1;
    check(validateTask($working, $submitted, false)['department'] === 'المهمات', 'manager bypasses self-review when the only child is complete');
    \App\Models\Task::$hasUnfinishedChildren = true;
    reject($working, $submitted, 422, false);
    \App\Models\Task::$hasUnfinishedChildren = false;
    \App\Models\Task::$finishedChildren = 0;
    $employeeWorking = array_replace($old, ['employeeState'=>'working']);
    $employeeSubmitted = validateTask($employeeWorking, array_replace($employeeWorking, ['employeeState'=>'submitted']), false, 'E1');
    check($employeeSubmitted['department'] === $department && $employeeSubmitted['employeeState'] === 'submitted'
        && $employeeSubmitted['reviewManager'] === 'Department manager', 'employee submits to their manager first');
    $employeeFollowUp = validateTask($employeeSubmitted, array_replace($employeeSubmitted, [
        'department'=>'المحاسبة','status'=>'awaiting_invoice','employeeState'=>'finished','closedFromDepartment'=>$department,
    ]), false);
    check($employeeFollowUp['department'] === 'المهمات' && $employeeFollowUp['employeeState'] === 'finished',
        'manager approval sends employee work to follow-up');
    $employeeBilling = array_replace($employeeFollowUp, ['department'=>'المحاسبة','status'=>'awaiting_invoice','closedFromDepartment'=>'المهمات']);
    check(validateTask($employeeFollowUp, $employeeBilling, true, 'FOLLOW-UP') === $employeeBilling, 'follow-up sends employee work to billing');
    check(validateTask($finished, $finished) === $finished, 'finished department stage survives later saves');
    $transferred = array_replace($finished, ['department'=>'التصميم', 'assigneeId'=>'', 'assignee'=>'', 'employeeState'=>'new']);
    check(validateTask($finished, $transferred) === $transferred, 'manager can separately transfer the finished task and start a new stage');
    $child = validateTask($working + ['parentTaskId'=>'P1'], $submitted + ['parentTaskId'=>'P1'], false);
    check($child['status'] === 'completed' && $child['department'] === $department && isset($child['completedAt']), 'manager completes a child in its source department');
    \App\Models\Task::$hasChildren = true;
    check(validateTask($working, $submitted, false)['employeeState'] === 'submitted', 'manager submission preserves package workflow');
    \App\Models\Task::$hasChildren = false;
    \App\Models\Setting::$managers[$department] = 'E1';
    \App\Models\Employee::$rows['MANAGER']->data['department'] = $department;
    check(validateTask($working, $submitted)['employeeState'] === 'submitted', 'an executor with approval permission but without department management still submits for review');
    foreach ($departments as $department) {
        \App\Models\Setting::$managers = [$department => 'MANAGER'];
        $workingInDepartment = array_replace($working, ['department'=>$department]);
        $submission = array_replace($workingInDepartment, ['employeeState'=>'submitted']);
        $finishedInDepartment = validateTask($workingInDepartment, $submission, false);
        check($finishedInDepartment['department'] === ($department === 'المحاسبة' ? $department : 'المهمات')
            && $finishedInDepartment['status'] === 'in_progress'
            && $finishedInDepartment['employeeState'] === 'finished',
            'manager finishes their own task and sends eligible work to follow-up: '.$department);
        $destination = $department === 'التصميم' ? 'التسويق' : 'التصميم';
        $transfer = array_replace($finishedInDepartment, ['department'=>$destination, 'assigneeId'=>'', 'assignee'=>'', 'employeeState'=>'new']);
        check(validateTask($finishedInDepartment, $transfer, false) === $transfer,
            'manager can explicitly transfer a finished task from: '.$department);
    }
    echo "OK: $checks billing handoff and permission checks\n";
}

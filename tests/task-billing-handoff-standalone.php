<?php
// Isolated BusinessRules regression: no Laravel boot, database, HTTP, or telemetry.
namespace {
    function abort_unless($condition, $status, $message = ''): void {
        if (!$condition) throw new \RuntimeException($message ?: 'Forbidden', $status);
    }
}
namespace App\Models {
    class User {
        public string $employee_id = 'MANAGER';
        public function __construct(public bool $approve = true) {}
        public function allows($module, $action = 'view'): bool { return $action === 'approve' && $this->approve; }
        public function manages($department): bool { return false; }
        public function root(): bool { return false; }
    }
    class Employee {
        public static array $rows = [];
        public static function find($id): ?object { return self::$rows[(string)$id] ?? null; }
    }
    class Setting { public static function valueOf($key): array { return []; } }
    class Task {
        public static function where(...$args): self { return new self; }
        public function exists(): bool { return false; }
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
    function validateTask(array $old, array $next, bool $approve = true): array {
        global $rule; $actor = new \App\Models\User($approve);
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
        check(validateTask($old, $next) === $next, 'approved handoff must retain executor and data: '.$department);
        reject($old, $next, 403, false);
    }
    // Exercise cross-department cases separately from the accounting-to-accounting fixture.
    $old['department'] = 'التسويق';$next['closedFromDepartment'] = 'التسويق';
    \App\Models\Employee::$rows['E1']->data['department'] = 'التسويق';
    \App\Models\Employee::$rows['E2'] = (object)['id'=>'E2','data'=>['department'=>'التصميم','active'=>true]];
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
    echo "OK: $checks billing handoff and permission checks\n";
}

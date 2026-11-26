<?php

namespace Tests\Feature;

use App\Models\{Employee, Role, Setting, Task, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class TaskReadQueryTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $id, string $scope, array $data = []): User
    {
        $root = $scope === 'root';
        $roleId = $root ? 'app_programmer' : 'role-'.$id;
        Role::firstOrCreate(['id' => $roleId], [
            'name' => $roleId,
            'data' => [
                'taskScope' => $scope,
                'scope' => 'custom',
                'modules' => ['marketing' => ['list' => true, 'details' => true]],
            ],
        ]);
        Employee::create([
            'id' => 'E-'.$id, 'status' => 'active', 'department' => 'التسويق',
            'data' => ['name' => $id, 'department' => 'التسويق', 'employmentStatus' => 'active'],
        ]);

        return User::create([
            'id' => $id, 'name' => $id, 'username' => $id, 'password' => 'Synthetic-password123!',
            'role_id' => $roleId, 'employee_id' => 'E-'.$id, 'department' => 'التسويق',
            'active' => true, 'is_root' => $root, 'must_change' => false, 'data' => $data,
        ]);
    }

    private function authenticate(User $user): void
    {
        $this->actingAs($user)->withSession(['auth_version' => $user->auth_version, 'last_activity' => time()]);
    }

    private function task(string $id, array $data = [], array $columns = []): void
    {
        $document = $data + [
            'title' => $id, 'department' => 'التسويق', 'status' => 'in_progress',
            'createdAt' => '2026-10-01T10:00:00Z',
        ];
        Task::create($columns + [
            'id' => $id, 'department' => $document['department'], 'status' => $document['status'],
            'data' => $document, 'created_at' => '2026-10-01 10:00:00',
        ]);
    }

    public function test_main_task_list_pages_at_sql_and_preserves_sort_urgency_and_arabic_search(): void
    {
        $this->seed();
        $this->authenticate($this->account('read-root', 'root'));
        $this->task('normal', ['title' => 'تصميم ألف']);
        $this->task('urgent', ['title' => 'تصميم ياء', 'priority' => 'urgent']);
        $this->task('normal-legacy', ['title' => 'تصميم باء', 'isUrgent' => false, 'urgent' => true]);
        $this->task('child', ['title' => 'تصميم فرعي', 'parentTaskId' => 'normal']);
        $this->task('archive', ['title' => 'تصميم مؤرشف', 'status' => 'archived_no_invoice']);
        $this->task('deleted', ['title' => 'تصميم محذوف', 'deletedAt' => '2026-10-02']);

        DB::enableQueryLog();
        $response = $this->getJson('/api/tasks?view=main&perPage=1&sort=title&filter[status]=in_progress&q='.urlencode('تصميم'))
            ->assertOk()->assertJsonPath('data.0.id', 'urgent')->assertJsonPath('meta.total', 3)->assertJsonPath('meta.lastPage', 3);
        $sql = implode("\n", array_column(DB::getQueryLog(), 'query'));
        DB::disableQueryLog();
        $response->assertJsonCount(1, 'data');
        $this->assertMatchesRegularExpression('/from ["\x60]tasks["\x60].*limit 1/s', $sql);
    }

    public function test_sql_scopes_and_summary_match_detail_permission_with_legacy_rows(): void
    {
        $this->seed();
        $manager = $this->account('scope-manager', 'department', ['moduleOverrides' => ['design' => ['view' => false]]]);
        Setting::findOrFail('department-managers')->update(['value' => ['التسويق' => $manager->employee_id]]);
        $this->authenticate($manager);
        $this->task('marketing');
        $this->task('owned-legacy', ['department' => 'قسم مخصص', 'employeeId' => $manager->employee_id]);
        $this->task('denied-owned', ['department' => 'التصميم', 'assigneeId' => $manager->employee_id]);
        $this->task('explicit-empty', ['department' => 'قسم مخصص', 'assigneeId' => null, 'employeeId' => $manager->employee_id]);
        $this->task('outside', ['department' => 'قسم مخصص']);

        $list = $this->getJson('/api/tasks')->assertOk()->assertJsonPath('meta.total', 2)->json('data');
        $this->assertSame(['marketing', 'owned-legacy'], array_column($list, 'id'));
        $this->getJson('/api/tasks/owned-legacy')->assertOk();
        $this->getJson('/api/tasks/denied-owned')->assertNotFound();
        $this->getJson('/api/tasks/explicit-empty')->assertNotFound();
        $this->getJson('/api/task-views/summary')->assertOk()->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.byStatus.in_progress', 2);
        $this->getJson('/api/task-views/mine')->assertOk()->assertJsonPath('data.0.id', 'owned-legacy')->assertJsonCount(1, 'data');
    }

    public function test_bootstrap_omits_task_queries_and_task_family_keeps_permission_boundaries(): void
    {
        $this->seed();
        $employee = $this->account('family-employee', 'mine');
        $this->authenticate($employee);
        $this->task('root');
        $this->task('mine', ['parentTaskId' => 'root', 'assigneeId' => $employee->employee_id], [
            'parent_id' => 'root', 'employee_id' => $employee->employee_id,
        ]);
        $this->task('private-sibling', ['parentTaskId' => 'root'], ['parent_id' => 'root']);

        DB::enableQueryLog();
        $omitted = $this->getJson('/api/bootstrap?section=tasks&tasks=omit')->assertOk()
            ->assertJsonCount(0, 'data.state.salesTasks')->json('data.loadedResources');
        $sql = implode("\n", array_column(DB::getQueryLog(), 'query'));
        DB::disableQueryLog();
        $this->assertNotContains('tasks', $omitted);
        $this->assertDoesNotMatchRegularExpression('/from ["\x60]tasks["\x60]/i', $sql);
        $this->getJson('/api/bootstrap?section=dashboard&tasks=mine')->assertOk()
            ->assertJsonCount(1, 'data.state.salesTasks')->assertJsonPath('data.state.salesTasks.0.id', 'mine');
        $this->getJson('/api/tasks/mine')->assertOk()->assertJsonPath('data.id', 'mine');
        $this->getJson('/api/task-views/family/mine')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', 'mine');
        $this->getJson('/api/task-views/family/private-sibling')->assertNotFound();
    }
}


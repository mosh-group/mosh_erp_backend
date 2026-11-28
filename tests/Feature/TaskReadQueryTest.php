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

    public function test_platform_programs_persist_counts_and_preserve_task_visibility(): void
    {
        $this->seed();
        $url = '/api/platform/programs';
        $root = $this->account('platform-owner', 'root');
        $member = $this->account('platform-member', 'mine', ['moduleOverrides' => ['platform' => [
            'view' => true, 'dashboard' => true, 'create' => false, 'edit' => true,
        ]]]);
        $outside = $this->account('platform-outsider', 'mine');
        $this->task('PLATFORM/001', ['title' => 'مرحلة التصوير', 'department' => 'MOSH MEDIA',
            'assigneeId' => $member->employee_id, 'assignee' => 'موظف المنصة', 'notes' => 'internal-storyboard']);
        $this->task('PLATFORM/002', ['title' => 'مراجعة حلقات', 'department' => 'MOSH MEDIA']);
        $this->task('PLATFORM/DELETED', ['department' => 'MOSH MEDIA', 'deletedAt' => '2026-10-02']);
        $this->task('M/0001', ['title' => 'مهمة التسويق']);

        $this->getJson($url)->assertUnauthorized();
        $this->authenticate($outside->fresh());
        $this->getJson($url)->assertForbidden();
        $this->getJson($url.'/task-options')->assertForbidden();
        $this->postJson($url, [])->assertForbidden();

        $this->authenticate($root->fresh());
        $this->getJson($url)->assertOk()->assertJsonPath('data.canCreate', true)->assertJsonCount(0, 'data.programs');
        $this->getJson($url.'/task-options?q='.urlencode('التصوير'))->assertOk()
            ->assertJsonPath('data.tasks.0.id', 'PLATFORM/001')->assertJsonCount(1, 'data.tasks');
        $this->getJson($url.'/task-options?q=internal-storyboard')->assertOk()->assertJsonCount(0, 'data.tasks');

        $payload = ['name' => 'برنامج المنصة', 'participantCount' => 12, 'completedEpisodeCount' => 4,
            'taskIds' => ['PLATFORM/001', 'PLATFORM/002'], 'requestId' => 'platform-test-create-0001'];
        $this->postJson($url, array_replace($payload, ['taskIds' => ['M/0001']]))->assertUnprocessable();
        $this->postJson($url, array_replace($payload, ['taskIds' => ['PLATFORM/DELETED']]))->assertUnprocessable();
        $this->postJson($url, array_replace($payload, ['participantCount' => -1]))->assertUnprocessable();

        $created = $this->postJson($url, $payload)->assertCreated()->assertJsonPath('data.participantCount', 12)
            ->assertJsonPath('data.completedEpisodeCount', 4)->assertJsonPath('data.taskCount', 2)
            ->assertJsonPath('data.tasks.0', ['id' => 'PLATFORM/001', 'title' => 'مرحلة التصوير',
                'status' => 'in_progress', 'assignee' => 'موظف المنصة'])
            ->assertJsonPath('data._version', 1);
        $id = $created->json('data.id');
        $this->assertStringContainsString('no-store', $created->headers->get('Cache-Control'));
        $this->postJson($url, $payload)->assertCreated()->assertJsonPath('data.id', $id);
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data.programs')->assertJsonPath('data.programs.0.id', $id);

        $this->authenticate($member->fresh());
        $this->getJson($url)->assertOk()->assertJsonPath('data.programs.0.taskCount', 1)
            ->assertJsonPath('data.canCreate', false)->assertJsonPath('data.canEdit', true)
            ->assertJsonMissing(['id' => 'PLATFORM/002']);
        $this->getJson($url.'/task-options')->assertOk()->assertJsonCount(1, 'data.tasks')
            ->assertJsonPath('data.tasks.0.id', 'PLATFORM/001');
        $this->postJson($url, $payload)->assertForbidden();

        $edit = array_replace($payload, ['participantCount' => 13, 'taskIds' => ['PLATFORM/001'],
            '_version' => 1, 'requestId' => 'platform-test-edit-0001']);
        $this->putJson($url.'/'.$id, array_replace($edit, ['taskIds' => ['PLATFORM/002']]))->assertUnprocessable();
        $this->putJson($url.'/'.$id, $edit)->assertOk()->assertJsonPath('data._version', 2)
            ->assertJsonPath('data.participantCount', 13)->assertJsonPath('data.taskCount', 1);
        $this->putJson($url.'/'.$id, array_replace($edit, ['requestId' => 'platform-test-edit-stale']))->assertConflict();

        $this->authenticate($root->fresh());
        $this->getJson($url)->assertOk()->assertJsonPath('data.programs.0.participantCount', 13)
            ->assertJsonPath('data.programs.0.taskCount', 2);
        $moved = Task::findOrFail('PLATFORM/002');
        $moved->update(['department' => 'التسويق', 'data' => array_replace($moved->data, ['department' => 'التسويق'])]);
        $this->getJson($url)->assertOk()->assertJsonPath('data.programs.0.tasks.1.id', 'PLATFORM/002');
        $this->getJson($url.'/task-options')->assertOk()->assertJsonCount(1, 'data.tasks');

        $this->deleteJson($url.'/'.$id)->assertMethodNotAllowed();
        $this->getJson('/api/settings/_mosh_platform_program_'.$id)->assertNotFound();
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


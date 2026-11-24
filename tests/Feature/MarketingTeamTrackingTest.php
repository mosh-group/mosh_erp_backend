<?php
namespace Tests\Feature;

use App\Models\{Setting, Task, User};
use App\Support\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MarketingTeamTrackingTest extends TestCase
{
    use RefreshDatabase;

    private function staffAccount(string $name, string $role): User
    {
        $employee = $this->postJson('/api/employees', [
            'id' => 'E-'.$name, 'name' => 'Synthetic '.$name, 'department' => 'التسويق',
            'jobTitle' => 'Test', 'hireDate' => '2026-01-01', 'attendanceMode' => 'daily',
        ])->assertCreated()->json('data.id');
        $id = $this->postJson('/api/users', [
            'name' => 'Synthetic '.$name, 'username' => $name, 'password' => 'Synthetic-password123',
            'roleId' => $role, 'employeeId' => $employee, 'active' => true, 'mustChange' => false,
        ])->assertCreated()->json('data.id');
        return User::findOrFail($id);
    }

    private function record(User $creator, string $id, array $data = [], ?string $parent = null): void
    {
        $task = Task::create([
            'id' => $id, 'department' => 'المهمات', 'version' => 1, 'status' => 'in_progress',
            'parent_id' => $parent, 'data' => ['title' => $id, 'department' => 'المهمات'] + $data,
        ]);
        Audit::record($creator, 'tasks', 'tasks', $id, 'create', null, $task->document());
    }

    public function test_manager_tracks_team_roots_and_created_tasks_without_exposing_other_tasks(): void
    {
        $this->seed();
        $this->postJson('/api/auth/setup', [
            'name' => 'Synthetic Admin', 'username' => 'admin',
            'password' => 'Synthetic-password123', 'password_confirmation' => 'Synthetic-password123',
        ])->assertCreated();
        $manager = $this->staffAccount('manager_test', 'role_manager');
        $employee = $this->staffAccount('employee_test', 'role_employee');
        Setting::findOrFail('department-managers')->update(['value' => ['التسويق' => $manager->employee_id]]);

        $team = ['sharedDistributionRoot' => true, 'sharedDistributionMode' => 'team'];
        $this->record($manager, 'manager-team', $team);
        $this->record($manager, 'manager-task');
        $this->record($manager, 'team-child', ['sharedTeamContainer' => true], 'manager-team');
        $this->record($employee, 'marketing-team', $team + ['sharedOriginalDepartment' => 'التسويق']);
        $this->record($employee, 'unrelated-team', $team + ['sharedOriginalDepartment' => 'المبيعات']);

        $this->actingAs($manager)->withSession(['auth_version' => $manager->auth_version, 'last_activity' => time()]);
        foreach (['', '?followUp=1'] as $query) {
            $rows = $this->getJson('/api/marketing/task-tracking'.$query)->assertOk()->json('data.tasks');
            $this->assertEqualsCanonicalizing(['manager-team','manager-task','marketing-team'], array_column($rows, 'id'));
        }
        $this->actingAs($employee)->withSession(['auth_version' => $employee->auth_version, 'last_activity' => time()]);
        $this->getJson('/api/marketing/task-tracking?followUp=1')->assertForbidden();
        $this->getJson('/api/marketing/task-tracking')->assertOk()->assertJsonCount(0, 'data.tasks');
    }
}

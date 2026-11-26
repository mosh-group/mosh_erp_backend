<?php

namespace Tests\Feature;

use App\Models\{AuditEvent, Employee, Task, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Tests\TestCase;

final class HrAccountStatusTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Synthetic-password123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function createAccount(string $key, string $department, string $role = 'role_employee', array $extra = []): User
    {
        $employeeId = 'EMP/'.$key;
        Employee::create([
            'id' => $employeeId,
            'department' => $department,
            'version' => 1,
            'data' => [
                'id' => $employeeId,
                'name' => 'Fixture '.$key,
                'department' => $department,
                'active' => true,
                'employmentStatus' => 'active',
                'documents' => [['id' => 'DOC-KEEP', 'name' => 'Fixture document.pdf']],
                'attendanceRecords' => [['id' => 'PUNCH-KEEP', 'date' => '2026-10-01', 'hours' => 8]],
                'leaveRecords' => [['id' => 'LEAVE-KEEP', 'startDate' => '2026-10-02']],
            ],
        ]);

        return User::create(array_replace([
            'id' => 'USR-'.$key,
            'name' => 'Fixture account '.$key,
            'username' => 'fixture_'.$key,
            'password' => self::PASSWORD,
            'role_id' => $role,
            'employee_id' => $employeeId,
            'department' => $department,
            'active' => true,
            'must_change' => false,
            'is_root' => false,
            'auth_version' => 1,
            'version' => 1,
            'data' => ['taskScope' => 'inherit', 'landing' => 'inherit'],
        ], $extra));
    }

    private function signInAs(User $user, ?int $sessionVersion = null): void
    {
        $this->actingAs($user->fresh())->withSession([
            'auth_version' => $sessionVersion ?? $user->fresh()->auth_version,
            'last_activity' => time(),
        ]);
    }

    private function endpoint(User $user): string
    {
        return '/api/hr/account-status/'.rawurlencode($user->employee_id);
    }

    private function change(User $user, bool $active, int $version = 1): array
    {
        return ['accountId' => $user->id, 'active' => $active, '_version' => $version];
    }

    public function test_hr_freeze_and_reactivation_preserve_the_employee_and_expire_old_sessions(): void
    {
        $hr = $this->createAccount('hr', 'الموارد البشرية', 'role_manager');
        $worker = $this->createAccount('worker', 'التصميم');
        $employee = $worker->employee;
        $task = Task::create([
            'id' => 'TASK-KEEP',
            'employee_id' => $employee->id,
            'department' => $employee->department,
            'status' => 'in_progress',
            'version' => 1,
            'data' => ['title' => 'Fixture assigned task', 'attachments' => [['id' => 'FILE-KEEP']]],
        ]);
        $employeeBefore = $employee->getAttributes();
        $taskBefore = $task->fresh()->getAttributes();
        $changingFields = ['active', 'auth_version', 'version', 'updated_at', 'failed_attempts'];
        $accountBefore = Arr::except($worker->fresh()->getAttributes(), $changingFields);
        $path = $this->endpoint($worker);

        $lastActivity = time() - 90;
        $this->signInAs($worker);
        $this->withSession(['last_activity' => $lastActivity]);
        $probe = $this->getJson('/api/auth/session')->assertOk()->assertExactJson(['success' => true, 'data' => [
            'authenticated' => true, 'accountId' => $worker->id, 'frozen' => false,
        ]]);
        $probe->assertSessionHas('last_activity', $lastActivity);
        $this->assertEmpty($probe->headers->getCookies());
        $this->assertStringContainsString('no-store', $probe->headers->get('Cache-Control'));

        $this->signInAs($hr);
        $this->getJson($path)->assertOk()->assertExactJson(['success' => true, 'data' => [
            'accountId' => $worker->id, 'active' => true, '_version' => 1, 'canChange' => true, 'activationBlocked' => false,
        ]]);
        $this->putJson($path, $this->change($worker, false))->assertOk()
            ->assertJsonPath('data.active', false)->assertJsonPath('data._version', 2);
        $this->putJson($path, $this->change($worker, false, 2))->assertOk()->assertJsonPath('data._version', 2);
        $this->putJson($path, $this->change($worker, true))->assertConflict();
        $this->assertSame(2, $worker->fresh()->auth_version);

        $this->signInAs($worker, 1);
        $this->getJson('/api/auth/session')->assertOk()->assertExactJson(['success' => true, 'data' => [
            'authenticated' => false, 'accountId' => null, 'frozen' => true,
        ]]);
        $this->getJson('/api/auth/me')->assertUnauthorized()->assertJsonPath('reason', 'frozen');
        $credentials = ['username' => $worker->username, 'password' => self::PASSWORD];
        $this->postJson('/api/auth/login', $credentials)->assertUnauthorized();

        $this->signInAs($hr);
        $this->putJson($path, $this->change($worker, true, 2))->assertOk()
            ->assertJsonPath('data.active', true)->assertJsonPath('data._version', 3);
        $this->assertSame($employeeBefore, $employee->fresh()->getAttributes());
        $this->assertSame($taskBefore, $task->fresh()->getAttributes());
        $this->assertSame($accountBefore, Arr::except($worker->fresh()->getAttributes(), $changingFields));
        $this->assertSame(['freeze', 'unfreeze'], AuditEvent::where('record_id', $worker->id)
            ->whereIn('action', ['freeze', 'unfreeze'])->orderBy('id')->pluck('action')->all());

        $this->signInAs($worker, 1);
        $this->getJson('/api/auth/session')->assertOk()->assertExactJson(['success' => true, 'data' => [
            'authenticated' => false, 'accountId' => null, 'frozen' => false,
        ]]);
        $this->getJson('/api/auth/me')->assertUnauthorized();
        $this->postJson('/api/auth/login', $credentials)->assertOk()->assertJsonPath('data.user.id', $worker->id);
    }

    public function test_status_respects_hr_access_and_protects_privileged_accounts(): void
    {
        $hr = $this->createAccount('hr', 'الموارد البشرية', 'role_manager');
        $viewer = $this->createAccount('viewer', 'الموارد البشرية');
        $worker = $this->createAccount('worker', 'التصميم');
        $creator = $this->createAccount('creator', 'تكنولوجيا المعلومات', 'role_employee', [
            'data' => ['globalOverrides' => ['manageUsers' => true]],
        ]);
        $privileged = $this->createAccount('privileged', 'التصميم', 'role_employee', [
            'active' => false,
            'data' => ['globalOverrides' => ['managePermissions' => true]],
        ]);

        $this->signInAs($worker);
        $this->getJson($this->endpoint($worker))->assertForbidden();
        $this->putJson($this->endpoint($worker), $this->change($worker, false))->assertForbidden();

        $this->signInAs($viewer);
        $this->getJson($this->endpoint($worker))->assertOk()->assertJsonPath('data.canChange', false);
        $this->putJson($this->endpoint($worker), $this->change($worker, false))->assertForbidden();

        $this->signInAs($hr);
        $this->getJson($this->endpoint($hr))->assertOk()->assertJsonPath('data.canChange', false);
        $this->putJson($this->endpoint($hr), $this->change($hr, false))->assertForbidden();
        $this->getJson($this->endpoint($creator))->assertOk()->assertJsonPath('data.canChange', true);
        $this->putJson($this->endpoint($creator), $this->change($creator, false))->assertOk()
            ->assertJsonPath('data.active', false)->assertJsonPath('data.canChange', true);
        $this->getJson($this->endpoint($privileged))->assertOk()->assertJsonPath('data.canChange', false);
        $this->putJson($this->endpoint($privileged), $this->change($privileged, true))->assertForbidden();
    }

    public function test_account_link_and_employee_status_are_checked_on_reactivation(): void
    {
        $hr = $this->createAccount('hr', 'الموارد البشرية', 'role_manager');
        $worker = $this->createAccount('worker', 'التصميم', 'role_employee', ['active' => false]);
        $employee = $worker->employee;
        $employee->update(['data' => array_replace($employee->data, ['employmentStatus' => 'inactive', 'active' => false])]);

        $this->signInAs($hr);
        $this->getJson($this->endpoint($worker))->assertOk()
            ->assertJsonPath('data.canChange', false)->assertJsonPath('data.activationBlocked', true);
        $this->putJson($this->endpoint($worker), $this->change($worker, true))->assertUnprocessable();
        $this->putJson($this->endpoint($worker), array_replace($this->change($worker, true), ['accountId' => $hr->id]))->assertConflict();

        $path = $this->endpoint($worker);
        $worker->update(['employee_id' => null]);
        $this->getJson($path)->assertOk()->assertExactJson(['success' => true, 'data' => null]);
    }
}

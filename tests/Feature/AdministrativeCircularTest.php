<?php

namespace Tests\Feature;

use App\Models\{Employee, Setting, User};
use App\Support\R19Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdministrativeCircularTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function account(string $id, string $role = 'role_employee', array $data = []): User
    {
        $employee = Employee::create([
            'id' => 'EMP-'.$id, 'department' => 'التصميم', 'version' => 1,
            'data' => ['name' => 'Fixture employee '.$id, 'department' => 'التصميم', 'active' => true],
        ]);

        return User::create([
            'id' => 'USR-'.$id, 'name' => 'Fixture '.$id, 'username' => 'fixture_'.$id,
            'password' => 'Synthetic-password123!', 'role_id' => $role, 'employee_id' => $employee->id,
            'department' => 'التصميم', 'active' => true, 'is_root' => false, 'must_change' => false,
            'auth_version' => 1, 'version' => 1, 'data' => $data,
        ]);
    }

    private function signIn(User $account): void
    {
        $this->actingAs($account->fresh())->withSession(['auth_version' => 1, 'last_activity' => time()]);
    }

    private function payload(int $days = 2): array
    {
        return ['title' => 'قرار إداري للاختبار', 'message' => "نص القرار\nالسطر الثاني", 'days' => $days, 'requestId' => 'test-circular-publish-01'];
    }

    public function test_circular_requires_its_own_permission_and_valid_duration(): void
    {
        $publisher = $this->account('publisher', 'role_employee', [
            'globalOverrides' => ['publishAdministrativeCirculars' => true],
        ]);
        $manager = $this->account('manager', 'role_manager', ['globalOverrides' => ['notifications' => true]]);
        $this->signIn($manager);
        $this->postJson('/api/r19/administrative-circulars', $this->payload())->assertForbidden();

        $this->signIn($publisher);
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.effectivePermissions.globals.publishAdministrativeCirculars', true);
        $this->postJson('/api/r19/administrative-circulars', $this->payload(0))->assertUnprocessable();
        $this->postJson('/api/r19/administrative-circulars', $this->payload() + ['displaySeconds' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors('displaySeconds');
        $this->postJson('/api/r19/administrative-circulars', $this->payload() + ['displaySeconds' => 2.5])
            ->assertUnprocessable()->assertJsonValidationErrors('displaySeconds');
        $this->postJson('/api/r19/administrative-circulars', $this->payload() + ['repeatOnRefresh' => 'invalid'])
            ->assertUnprocessable()->assertJsonValidationErrors('repeatOnRefresh');
        $documentId = $this->postJson('/api/r19/administrative-circulars', $this->payload())
            ->assertCreated()->assertJsonStructure(['data' => ['id', 'expiresAt']])->json('data.id');

        $legacy = Setting::findOrFail('r19-administrative-circular:'.$documentId);
        $document = $legacy->value;
        unset($document['displaySeconds'], $document['repeatOnRefresh']);
        $legacy->update(['value' => $document]);
        $this->getJson('/api/r19/notifications')->assertOk()
            ->assertJsonPath('data.items.0.displaySeconds', null)
            ->assertJsonPath('data.items.0.repeatOnRefresh', false);
    }

    public function test_every_account_gets_the_circular_and_only_its_own_acknowledgement(): void
    {
        $this->freezeSecond();
        $publisher = $this->account('publisher', 'role_employee', [
            'globalOverrides' => ['publishAdministrativeCirculars' => true],
        ]);
        $staff = $this->account('staff', 'role_employee', ['moduleOverrides' => ['hr' => ['view' => false, 'details' => false]]]);
        $manager = $this->account('manager', 'role_manager');
        Setting::findOrFail('department-managers')->update(['value' => ['التصميم' => $manager->employee_id]]);

        $this->signIn($publisher);
        $options = $this->payload() + ['displaySeconds' => 7, 'repeatOnRefresh' => true];
        $result = $this->postJson('/api/r19/administrative-circulars', $options)
            ->assertCreated()->assertJsonPath('data.expiresAt', now('UTC')->addDays(2)->toIso8601String())->json('data');
        $this->postJson('/api/r19/administrative-circulars', $options)->assertCreated()->assertJsonPath('data.id', $result['id']);
        $this->assertCount(1, R19Store::prefixed('r19-administrative-circular:'));

        $this->signIn($staff);
        $item = $this->getJson('/api/r19/notifications')->assertOk()->assertJsonPath('data.unread', 1)
            ->assertJsonPath('data.items.0.type', 'administrative-circular')
            ->assertJsonPath('data.items.0.body', $this->payload()['message'])
            ->assertJsonPath('data.items.0.displaySeconds', 7)
            ->assertJsonPath('data.items.0.repeatOnRefresh', true)
            ->assertJsonPath('data.items.0.expiresAt', $result['expiresAt'])->json('data.items.0');
        $this->assertNull(R19Store::get('r19-notifications:'.hash('sha256', $staff->id)));
        $this->postJson('/api/r19/notifications/'.$item['id'].'/acknowledge', ['requestId' => 'test-circular-receipt-01'])
            ->assertOk()->assertJsonPath('data.inbox.unread', 0);
        $this->getJson('/api/r19/notifications')->assertOk()->assertJsonPath('data.unread', 0)
            ->assertJsonPath('data.items.0.displaySeconds', 7)
            ->assertJsonPath('data.items.0.repeatOnRefresh', true);

        $this->signIn($manager);
        $this->getJson('/api/r19/notifications')->assertOk()->assertJsonPath('data.unread', 1);
        $this->travel(2)->days();
        $this->signIn($manager);
        $this->getJson('/api/r19/notifications')->assertOk()->assertJsonCount(0, 'data.items');

        $this->signIn($staff);
        $this->getJson('/api/r19/notifications')->assertOk()->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $item['id'])->assertJsonPath('data.unread', 0);
    }
}

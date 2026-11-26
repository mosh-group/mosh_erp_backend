<?php

namespace Tests\Feature;

use App\Models\{Customer, Role, Task, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class TaskIdReservationsTest extends TestCase
{
    use RefreshDatabase;

    private function actor(string $id, bool $canCreate, bool $root = false): User
    {
        $role = $root ? 'app_programmer' : 'role-'.$id;
        Role::firstOrCreate(['id' => $role], [
            'name' => $role, 'data' => ['globals' => ['createTasks' => $canCreate]],
        ]);

        return User::create([
            'id'=>$id, 'name'=>$id, 'username'=>$id, 'password'=>'Synthetic-password123!',
            'role_id'=>$role, 'department'=>'التسويق', 'active'=>true, 'is_root'=>$root,
            'must_change'=>false, 'data'=>[],
        ])->refresh();
    }

    private function authenticate(User $user): void
    {
        $this->actingAs($user)->withSession(['auth_version'=>$user->auth_version, 'last_activity'=>time()]);
    }

    private function taskData(): array
    {
        return ['title'=>'مهمة', 'department'=>'المهمات', 'status'=>'in_progress',
            'customer'=>'شركة الاختبار', 'customerId'=>'range-customer'];
    }

    public function test_reservation_starts_after_the_existing_numeric_maximum_and_never_wraps(): void
    {
        $this->seed();
        $this->authenticate($this->actor('main-allocator', true, true));
        foreach (['57', '00081', 'project-90', '998877665544332211000'] as $id) {
            Task::create(['id'=>$id, 'data'=>$this->taskData()]);
        }
        $revision = DB::table('app_locks')->where('id', 'business')->value('revision');

        $this->postJson('/api/task-views/reserve', ['count'=>3])->assertOk()
            ->assertExactJson(['success'=>true, 'data'=>['first'=>82, 'last'=>84]]);
        $this->postJson('/api/task-views/reserve', ['count'=>1])->assertOk()
            ->assertJsonPath('data.first', 85)->assertJsonPath('data.last', 85);
        $this->postJson('/api/task-views/reserve', ['count'=>501])->assertUnprocessable();
        $this->assertEquals($revision, DB::table('app_locks')->where('id', 'business')->value('revision'));
    }

    public function test_legacy_numeric_creates_advance_the_counter_and_respect_reserved_ranges(): void
    {
        $this->seed();
        Customer::create(['id'=>'range-customer', 'data'=>['name'=>'شركة الاختبار']]);
        $allocator = $this->actor('allocator', true, true);
        $legacy = $this->actor('legacy-client', true);
        $this->authenticate($allocator);
        $this->postJson('/api/task-views/reserve', ['count'=>2])->assertOk()->assertJsonPath('data.first', 1);

        $this->authenticate($legacy);
        $this->postJson('/api/tasks', $this->taskData() + ['id'=>'1'])->assertConflict();
        $this->postJson('/api/tasks', $this->taskData() + ['id'=>'22'])->assertCreated();
        $this->assertDatabaseHas('tasks', ['id'=>'22']);

        $this->authenticate($allocator);
        $this->postJson('/api/tasks', $this->taskData() + ['id'=>'1'])->assertCreated();
        $this->postJson('/api/tasks', $this->taskData() + ['id'=>'02'])->assertConflict();
        $this->postJson('/api/task-views/reserve', ['count'=>2])->assertOk()
            ->assertJsonPath('data.first', 23)->assertJsonPath('data.last', 24);
    }

    public function test_module_create_permission_allows_reservation_and_plain_accounts_cannot_reserve(): void
    {
        $this->seed();
        $plain = $this->actor('plain-readonly', false);
        $this->authenticate($plain);
        $this->postJson('/api/task-views/reserve', ['count'=>1])->assertForbidden();
        $this->assertDatabaseMissing('sequences', ['id'=>'task-number']);

        $plain->role->update(['data'=>['modules'=>['marketing'=>['create'=>true]]]]);
        $this->authenticate($plain->refresh());
        $this->postJson('/api/task-views/reserve', ['count'=>1])->assertOk()
            ->assertJsonPath('data.first', 1)->assertJsonPath('data.last', 1);
    }
}


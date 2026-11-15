<?php
namespace Tests\Feature;

use App\Models\{Announcement, Employee, Setting, Task, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class R19NotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        foreach (['1', '2'] as $number) {
            Employee::create(['id' => 'NE'.$number, 'department' => 'التصميم', 'data' => [
                'name' => 'موظف '.$number, 'department' => 'التصميم', 'active' => true,
            ]]);
            User::create(['id' => 'NU'.$number, 'name' => 'موظف '.$number, 'username' => 'notice'.$number,
                'password' => 'Notice-Password123!', 'role_id' => 'role_employee', 'employee_id' => 'NE'.$number,
                'department' => 'التصميم', 'active' => true, 'is_root' => false, 'must_change' => false,
                'auth_version' => 1, 'version' => 1, 'data' => []]);
        }
    }

    private function login(string $number = '1'): void
    {
        $this->postJson('/api/auth/login', ['username' => 'notice'.$number, 'password' => 'Notice-Password123!'])->assertOk();
    }

    private function taskRow(): Task
    {
        return Task::create(['id' => 'NT1', 'employee_id' => 'NE1', 'department' => 'التصميم', 'status' => 'in_progress',
            'data' => ['id' => 'NT1', 'title' => 'طلب تصميم', 'department' => 'التصميم', 'assigneeId' => 'NE1',
                'assignee' => 'موظف 1', 'status' => 'in_progress', 'employeeState' => 'new',
                'createdAt' => '2026-10-01T08:00:00+03:00', 'workChat' => [], 'workTiming' => ['totalMs' => 0, 'activeStartedAt' => 0]]]);
    }

    public function test_task_notice_persists_until_ack_and_never_changes_task_clock(): void
    {
        $task = $this->taskRow()->fresh(); $before = $task->getAttributes();
        $this->login();
        $first = $this->getJson('/api/r19/notifications')->assertOk()->assertJsonPath('data.unread', 1)->json('data');
        $id = $first['items'][0]['id'];
        $this->assertSame($before, $task->fresh()->getAttributes());
        $version = $first['_version'];
        $this->getJson('/api/r19/notifications')->assertOk()->assertJsonPath('data._version', $version)->assertJsonPath('data.unread', 1);
        // A worker's real receive/finish in another view never acknowledges the notification.
        $data = $task->data; $data['employeeState'] = 'submitted'; $data['status'] = 'completed';
        $task->update(['data' => $data, 'status' => 'completed']);
        $clock = $task->fresh()->data['workTiming'];
        $this->getJson('/api/r19/notifications')->assertOk()->assertJsonPath('data.unread', 1);
        $payload = ['requestId' => 'notice-ack-0001'];
        $ack = $this->postJson('/api/r19/notifications/'.$id.'/acknowledge', $payload)->assertOk()->assertJsonPath('data.inbox.unread', 0)->json('data');
        $this->assertNotEmpty($ack['readAt']);
        $this->postJson('/api/r19/notifications/'.$id.'/acknowledge', $payload)->assertOk()->assertJsonPath('data.readAt', $ack['readAt']);
        $this->assertSame($clock, $task->fresh()->data['workTiming']);
        $this->postJson('/api/auth/logout')->assertOk(); $this->login();
        $this->getJson('/api/r19/notifications')->assertOk()->assertJsonPath('data.unread', 0)->assertJsonPath('data.items.0.readAt', $ack['readAt']);
        $this->assertSame(1, DB::table('transactions')->where('actor_id', 'NU1')->where('idempotency_key', 'r19:notice-ack-0001')->count());
    }

    public function test_receipts_and_content_cannot_be_accessed_by_another_account(): void
    {
        $task = $this->taskRow(); $this->login();
        $id = $this->getJson('/api/r19/notifications')->assertOk()->json('data.items.0.id');
        $this->postJson('/api/auth/logout')->assertOk(); $this->login('2');
        $this->getJson('/api/r19/notifications')->assertOk()->assertJsonPath('data.items', []);
        $this->postJson('/api/r19/notifications/'.$id.'/acknowledge', ['requestId' => 'notice-foreign-0001'])->assertNotFound();
        $this->getJson('/api/settings/r19-notifications:'.hash('sha256', 'NU1'))->assertNotFound();
        $this->withHeader('Idempotency-Key', 'notification-private-write')->postJson('/api/transactions', ['operations' => [['resource' => 'settings/r19-notifications:'.hash('sha256', 'NU1'), 'action' => 'update', 'version' => 1, 'data' => ['items' => []]]]])->assertNotFound();
        $this->flushHeaders();
        $this->postJson('/api/auth/logout')->assertOk(); $this->login();
        $data = $task->data; $data['assigneeId'] = 'NE2'; $data['assignee'] = 'موظف 2';
        $task->update(['data' => $data, 'employee_id' => 'NE2']);
        $this->getJson('/api/r19/notifications')->assertOk()->assertJsonPath('data.items', []);
        $this->postJson('/api/r19/notifications/'.$id.'/acknowledge', ['requestId' => 'notice-revoked-0001'])->assertNotFound();
    }

    public function test_return_cycle_has_separate_receipt_and_retry_does_not_duplicate(): void
    {
        $task = $this->taskRow(); $this->login();
        $id = $this->getJson('/api/r19/notifications')->assertOk()->json('data.items.0.id');
        $this->postJson('/api/r19/notifications/'.$id.'/acknowledge', ['requestId' => 'notice-cycle-initial'])->assertOk();
        $d = $task->data; $d['returnedForRevision'] = true; $d['employeeState'] = 'paused';
        $d['workChat'][] = ['id' => 'MR49/return/cycle2', 'kind' => 'text', 'text' => 'المطلوب تعديله: اللون', 'time' => '2026-10-02T10:00:00+03:00'];
        $task->update(['data' => $d]);
        $response = $this->getJson('/api/r19/notifications')->assertOk()->assertJsonPath('data.unread', 1)->json('data');
        $this->assertCount(2, $response['items']);
        $this->getJson('/api/r19/notifications')->assertOk()->assertJsonCount(2, 'data.items')->assertJsonPath('data.unread', 1);
        $return = collect($response['items'])->first(fn($n) => $n['id'] !== $id);
        $this->postJson('/api/r19/notifications/'.$return['id'].'/acknowledge', ['requestId' => 'notice-cycle-initial'])->assertConflict();
    }

    public function test_announcement_ack_is_atomic_with_original_receipt_and_archive(): void
    {
        User::create(['id' => 'NROOT', 'name' => 'مدير النظام', 'username' => 'noticeadmin',
            'password' => 'Notice-Password123!', 'role_id' => 'app_programmer', 'department' => 'الإدارة',
            'active' => true, 'is_root' => true, 'must_change' => false, 'auth_version' => 1, 'version' => 1, 'data' => []]);
        $this->postJson('/api/auth/login', ['username' => 'noticeadmin', 'password' => 'Notice-Password123!'])->assertOk();
        // Creation uses the real original workflow, including its employee archive copy.
        $this->postJson('/api/announcements', ['id' => 'NA1', 'title' => 'تعليمات اليوم', 'message' => 'نص التعميم',
            'type' => 'alert', 'targetType' => 'employee', 'targetValue' => 'NE1', 'active' => true])->assertCreated();
        $this->postJson('/api/auth/logout')->assertOk();
        $this->login();
        $id = $this->getJson('/api/r19/notifications')->assertOk()->assertJsonPath('data.unread', 1)->json('data.items.0.id');
        $this->assertSame([], Announcement::find('NA1')->data['readBy']);
        $this->postJson('/api/r19/notifications/'.$id.'/acknowledge', ['requestId' => 'announcement-ack-0001'])->assertOk()->assertJsonPath('data.inbox.unread', 0);
        $this->assertSame(['NE1'], Announcement::find('NA1')->data['readBy']);
        $this->assertNotEmpty(Announcement::find('NA1')->data['readAtBy']['NE1']);
        $this->getJson('/api/r19/notifications')->assertOk()->assertJsonPath('data.unread', 0);
        $this->postJson('/api/auth/logout')->assertOk(); $this->login('2');
        $this->getJson('/api/r19/notifications')->assertOk()->assertJsonPath('data.items', []);
    }

    public function test_receipt_from_another_view_is_reconciled_before_ack_reply(): void
    {
        Announcement::create(['id' => 'NA2', 'data' => ['id' => 'NA2', 'title' => 'تعميم', 'message' => 'نص',
            'type' => 'announcement', 'targetType' => 'employee', 'targetValue' => 'NE1',
            'recipientIds' => ['NE1'], 'active' => true, 'readBy' => [], 'readAtBy' => []]]);
        $this->login();
        $id = $this->getJson('/api/r19/notifications')->assertOk()->assertJsonPath('data.unread', 1)->json('data.items.0.id');
        $this->patchJson('/api/announcements/NA2', ['_version' => 1, 'readBy' => ['NE1'], 'readAtBy' => []])->assertOk();
        $reply = $this->postJson('/api/r19/notifications/'.$id.'/acknowledge', ['requestId' => 'parallel-view-ack-0001'])
            ->assertOk()->assertJsonPath('data.inbox.unread', 0)->json('data');
        $this->assertNotEmpty($reply['readAt']);
        $this->getJson('/api/r19/notifications')->assertOk()->assertJsonPath('data.items.0.readAt', $reply['readAt']);
    }
}

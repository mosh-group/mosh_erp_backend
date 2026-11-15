<?php
namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use App\Models\{Task,Employee,WorkPresence};

/** Run against an EMPTY TEST database, never the production database. */
class TaskTimerR7Test extends TestCase
{
    use RefreshDatabase;
    private int $clock;
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-27T09:00:00Z'));
        $this->clock=now()->getTimestampMs();
        $this->seed();
        $this->postJson('/api/auth/setup',['name'=>'QA Admin','username'=>'qa_admin','password'=>'Test-only-Secret123!','password_confirmation'=>'Test-only-Secret123!'])->assertCreated();
        $this->postJson('/api/customers',['id'=>'QA-C','name'=>'QA Client'])->assertCreated();
        foreach(['QA-E1','QA-E2'] as $id){
            $this->postJson('/api/employees',['id'=>$id,'name'=>$id,'department'=>'التصميم','active'=>true])->assertCreated();
            $this->postJson('/api/users',['name'=>$id,'username'=>strtolower($id),'employeeId'=>$id,'roleId'=>'role_employee','password'=>'Test-only-Secret123!','mustChange'=>false,'active'=>true])->assertCreated();
            $this->postJson('/api/tasks',['id'=>'T-'.$id,'title'=>'Test Task','customer'=>'QA Client','department'=>'التصميم','assigneeId'=>$id,'assignee'=>$id,'status'=>'in_progress','employeeState'=>'new'])->assertCreated();
        }
        $this->login('qa-e1');
    }
    private function login(string $name): void { $this->postJson('/api/auth/logout')->assertOk();$this->postJson('/api/auth/login',['username'=>$name,'password'=>'Test-only-Secret123!'])->assertOk(); }
    private function taskPatch(array $p,string $id='T-QA-E1'): array {return $this->patchJson('/api/tasks/'.$id,['_version'=>Task::findOrFail($id)->version,...$p])->assertOk()->json('data');}
    private function step(int $seconds): void { $this->clock+=$seconds*1000;$this->travelTo(Carbon::createFromTimestampMs($this->clock)); }
    public function test_status_only_start_creates_server_timer_without_attendance(): void
    {
        $this->taskPatch(['employeeState'=>'received']);
        $this->assertEmpty(Employee::find('QA-E1')->data['attendanceRecords']??[]);
        $d=$this->taskPatch(['employeeState'=>'working']);
        $this->assertSame($this->clock,$d['workTiming']['activeStartedAt']??null);
        $this->assertSame(0,$d['workTiming']['totalMs']);
        $this->assertSame('QA-E1',$d['workTiming']['activeWorkerId']);
        $this->step(5);
        $this->getJson('/api/tasks/T-QA-E1')->assertOk()->assertJsonPath('data.workTiming.activeStartedAt',$this->clock-5000);
    }
    public function test_receive_does_not_start_execution(): void
    {
        $d=$this->taskPatch(['employeeState'=>'received']);
        $this->assertSame(0,$d['workTiming']['activeStartedAt']);
        $this->assertSame($this->clock,$d['workTiming']['receivedAt']);
    }
    public function test_pause_resume_accumulates_only_work_and_submission_closes_once(): void
    {
        $this->taskPatch(['employeeState'=>'received']);$this->taskPatch(['employeeState'=>'working']);
        $this->step(120);$a=$this->taskPatch(['employeeState'=>'paused']);
        $this->assertSame(120000,$a['workTiming']['totalMs']);$this->assertCount(1,$a['workTiming']['sessions']);
        $this->step(600);$a=$this->taskPatch(['employeeState'=>'working']);
        $this->assertSame($this->clock,$a['workTiming']['activeStartedAt']);$this->assertSame(120000,$a['workTiming']['totalMs']);
        $this->step(180);$a=$this->taskPatch(['employeeState'=>'submitted','returnedForRevision'=>false]);
        $this->assertSame(300000,$a['workTiming']['totalMs']);$this->assertSame(0,$a['workTiming']['activeStartedAt']);
        $this->assertSame($this->clock,$a['workTiming']['finishedAt']);$this->assertCount(2,$a['workTiming']['sessions']);
        $this->step(60);$a=$this->taskPatch(['employeeState'=>'submitted']);$this->assertSame(300000,$a['workTiming']['totalMs']);
        $this->getJson('/api/tasks/T-QA-E1')->assertOk()->assertJsonPath('data.workTiming.totalMs',300000);
    }
    public function test_client_cannot_forge_execution_totals_sessions_or_reset_start(): void
    {
        $this->taskPatch(['employeeState'=>'received']);
        $a=$this->taskPatch(['employeeState'=>'working','workTiming'=>['activeStartedAt'=>1,'totalMs'=>999999999999,'sessions'=>[['startAt'=>1,'endAt'=>9999999999999]],'activeWorkerId'=>'QA-E2']]);
        $this->assertSame($this->clock,$a['workTiming']['activeStartedAt']);$this->assertSame(0,$a['workTiming']['totalMs']);$this->assertCount(0,$a['workTiming']['sessions']);
        $this->step(60);$a=$this->taskPatch(['workTiming'=>['activeStartedAt'=>0,'totalMs'=>-100,'sessions'=>[]]]);
        $this->assertSame($this->clock-60000,$a['workTiming']['activeStartedAt']);
        $a=$this->taskPatch(['employeeState'=>'paused','workTiming'=>['activeStartedAt'=>9999999999999,'totalSeconds'=>99999,'totalMs'=>999]]);
        $this->assertSame(60000,$a['workTiming']['totalMs']);$this->assertSame(0,$a['workTiming']['activeStartedAt']);
        $this->assertArrayNotHasKey('totalSeconds',$a['workTiming']);
    }
    public function test_missing_legacy_start_is_only_repaired_by_explicit_command(): void
    {
        $row=Task::find('T-QA-E1');$d=$row->data;$d['employeeState']='working';$d['workTiming']=['activeStartedAt'=>0,'totalMs'=>120000,'sessions'=>[]];$row->update(['data'=>$d]);
        $this->getJson('/api/tasks/T-QA-E1')->assertJsonPath('data.workTiming.activeStartedAt',0);
        $a=$this->taskPatch(['notes'=>'Editing notes must not invent a start.']);$this->assertSame(0,$a['workTiming']['activeStartedAt']);
        $a=$this->taskPatch(['employeeState'=>'working','workTiming'=>['_command'=>'repair_missing']]);
        $this->assertSame($this->clock,$a['workTiming']['activeStartedAt']);$this->assertSame(120000,$a['workTiming']['totalMs']);
        $this->assertTrue($a['workTiming']['historyIncomplete']);$this->assertNotEmpty($a['workTiming']['unmeasuredSessions']);
        $this->assertNull($a['workTiming']['unmeasuredSessions'][0]['durationMs']);$this->assertArrayNotHasKey('_command',$a['workTiming']);
        $this->step(45);$a=$this->taskPatch(['employeeState'=>'paused']);$this->assertSame(165000,$a['workTiming']['totalMs']);
    }
    public function test_missing_legacy_start_does_not_count_from_1970_when_paused(): void
    {
        $row=Task::find('T-QA-E1');$d=$row->data;$d['employeeState']='working';$d['workTiming']=['activeStartedAt'=>0,'totalMs'=>5000,'sessions'=>[]];$row->update(['data'=>$d]);
        $a=$this->taskPatch(['employeeState'=>'paused']);$this->assertSame(5000,$a['workTiming']['totalMs']);$this->assertCount(0,$a['workTiming']['sessions']);
        $this->assertTrue($a['workTiming']['historyIncomplete']);
        $this->step(20);$a=$this->taskPatch(['employeeState'=>'working']);$this->assertSame($this->clock,$a['workTiming']['activeStartedAt']);
    }
    public function test_two_active_tasks_for_one_employee_are_rejected_but_other_employees_can_work(): void
    {
        $this->taskPatch(['employeeState'=>'received']);$this->taskPatch(['employeeState'=>'working']);
        $this->login('qa_admin');
        $this->postJson('/api/tasks',['id'=>'T-EXTRA','title'=>'Second','customer'=>'QA Client','department'=>'التصميم','assigneeId'=>'QA-E1','status'=>'in_progress','employeeState'=>'new'])->assertCreated();
        $this->login('qa-e1');$this->taskPatch(['employeeState'=>'received'],'T-EXTRA');
        $this->patchJson('/api/tasks/T-EXTRA',['_version'=>Task::find('T-EXTRA')->version,'employeeState'=>'working'])->assertUnprocessable();
        $this->assertSame('received',Task::find('T-EXTRA')->data['employeeState']);
        $this->login('qa-e2');$this->taskPatch(['employeeState'=>'received'],'T-QA-E2');
        $a=$this->taskPatch(['employeeState'=>'working'],'T-QA-E2');$this->assertSame('QA-E2',$a['workTiming']['activeWorkerId']);
    }
    public function test_idempotent_retries_do_not_duplicate_sessions(): void
    {
        $this->taskPatch(['employeeState'=>'received']);$this->taskPatch(['employeeState'=>'working']);$this->step(60);
        $body=['operations'=>[['resource'=>'tasks','id'=>'T-QA-E1','action'=>'update','version'=>Task::find('T-QA-E1')->version,'data'=>['employeeState'=>'paused']]]];
        $a=$this->withHeader('Idempotency-Key','r7-identical-pause')->postJson('/api/transactions',$body)->assertOk()->json('data');
        $this->step(90);$b=$this->withHeader('Idempotency-Key','r7-identical-pause')->postJson('/api/transactions',$body)->assertOk()->json('data');
        $this->assertSame($a,$b);$this->assertCount(1,Task::find('T-QA-E1')->data['workTiming']['sessions']);
        $this->assertSame(60000,Task::find('T-QA-E1')->data['workTiming']['totalMs']);
    }
    public function test_task_and_presence_roll_back_together_on_failure(): void
    {
        $this->taskPatch(['employeeState'=>'received']);$v=Task::find('T-QA-E1')->version;
        $ops=[['resource'=>'tasks','id'=>'T-QA-E1','action'=>'update','version'=>$v,'data'=>['employeeState'=>'working']],
            ['resource'=>'work-presence','id'=>'id:QA-E1','action'=>'create','data'=>['mode'=>'INVALID']]];
        $this->withHeader('Idempotency-Key','r7-rollback-both')->postJson('/api/transactions',['operations'=>$ops])->assertUnprocessable();
        $this->assertSame('received',Task::find('T-QA-E1')->data['employeeState']);$this->assertSame($v,Task::find('T-QA-E1')->version);
        $this->assertSame(0,Task::find('T-QA-E1')->data['workTiming']['activeStartedAt']);$this->assertSame(0,WorkPresence::count());
    }
    public function test_actual_workdesk_transaction_pauses_task_for_break_and_resumes(): void
    {
        $this->taskPatch(['employeeState'=>'received']);$id='T-QA-E1';
        $ops=[['resource'=>'tasks','id'=>$id,'action'=>'update','version'=>Task::find($id)->version,'data'=>['employeeState'=>'working']],
            ['resource'=>'work-presence','id'=>'id:QA-E1','action'=>'create','data'=>['mode'=>'active','noWork'=>false,'pausedTaskId'=>'']]];
        $this->withHeader('Idempotency-Key','r7-start-transaction')->postJson('/api/transactions',['operations'=>$ops])->assertOk()->assertJsonPath('data.changes.0.data.workTiming.activeStartedAt',$this->clock);
        $this->step(90);
        $ops=[['resource'=>'tasks','id'=>$id,'action'=>'update','version'=>Task::find($id)->version,'data'=>['employeeState'=>'paused']],
            ['resource'=>'work-presence','id'=>'id:QA-E1','action'=>'update','version'=>WorkPresence::find('id:QA-E1')->version,'data'=>['mode'=>'break','noWork'=>false,'pausedTaskId'=>$id]]];
        $this->withHeader('Idempotency-Key','r7-break-transaction')->postJson('/api/transactions',['operations'=>$ops])->assertOk();
        $this->assertSame(90000,Task::find($id)->data['workTiming']['totalMs']);$this->step(300);
        $ops=[['resource'=>'tasks','id'=>$id,'action'=>'update','version'=>Task::find($id)->version,'data'=>['employeeState'=>'working']],
            ['resource'=>'work-presence','id'=>'id:QA-E1','action'=>'update','version'=>WorkPresence::find('id:QA-E1')->version,'data'=>['mode'=>'active','noWork'=>false,'pausedTaskId'=>'']]];
        $this->withHeader('Idempotency-Key','r7-resume-transaction')->postJson('/api/transactions',['operations'=>$ops])->assertOk();
        $this->assertSame($this->clock,Task::find($id)->data['workTiming']['activeStartedAt']);$this->assertSame(90000,Task::find($id)->data['workTiming']['totalMs']);
    }
    public function test_stale_version_cannot_restart_clock(): void
    {
        $this->taskPatch(['employeeState'=>'received']);$v=Task::find('T-QA-E1')->version;
        $a=$this->taskPatch(['employeeState'=>'working']);$this->step(60);
        $this->patchJson('/api/tasks/T-QA-E1',['_version'=>$v,'employeeState'=>'working'])->assertConflict();
        $this->assertSame($a['workTiming']['activeStartedAt'],Task::find('T-QA-E1')->data['workTiming']['activeStartedAt']);
    }
    public function test_valid_clock_cannot_be_repaired_to_reset_work(): void
    {
        $this->taskPatch(['employeeState'=>'received']);$a=$this->taskPatch(['employeeState'=>'working']);$this->step(120);
        $this->patchJson('/api/tasks/T-QA-E1',['_version'=>$a['_version'],'workTiming'=>['_command'=>'repair_missing']])->assertConflict();
        $this->assertSame($this->clock-120000,Task::find('T-QA-E1')->data['workTiming']['activeStartedAt']);
    }
    public function test_permission_denial_and_other_worker_block_start_and_repair(): void
    {
        $this->patchJson('/api/tasks/T-QA-E2',['_version'=>1,'employeeState'=>'working','workTiming'=>['_command'=>'repair_missing']])->assertForbidden();
        $this->taskPatch(['employeeState'=>'received']);
        $u=\App\Models\User::where('employee_id','QA-E1')->first();$data=$u->data;$data['moduleOverrides']['design']['status']=false;$u->update(['data'=>$data]);$this->login('qa-e1');
        $this->patchJson('/api/tasks/T-QA-E1',['_version'=>Task::find('T-QA-E1')->version,'employeeState'=>'working'])->assertForbidden();
    }
    public function test_legacy_seconds_iso_starts_are_normalized_without_reset(): void
    {
        foreach (['seconds'=>($this->clock-60000)/1000,'iso'=>Carbon::createFromTimestampMs($this->clock-60000)->toIso8601String()] as $kind=>$start){
            $r=Task::find('T-QA-E1');$d=$r->data;$d['employeeState']='working';$d['workTiming']=['activeStartedAt'=>$start,'totalMs'=>30000,'sessions'=>[]];$r->update(['data'=>$d]);
            $a=$this->taskPatch(['employeeState'=>'paused']);$this->assertSame(90000,$a['workTiming']['totalMs']);$this->assertSame($this->clock-60000,$a['workTiming']['sessions'][0]['startAt']);
        }
    }
    public function test_return_receipt_is_new_cycle_without_starting_clock_or_losing_total(): void
    {
        $this->taskPatch(['employeeState'=>'received']);$this->taskPatch(['employeeState'=>'working']);$this->step(60);$this->taskPatch(['employeeState'=>'submitted']);
        $this->login('qa_admin');$this->taskPatch(['employeeState'=>'paused','returnedForRevision'=>true,'workChat'=>[['id'=>'MR49/return/test-cycle','text'=>'المطلوب تعديله: اللون']]]);
        $this->login('qa-e1');$before=Task::find('T-QA-E1')->data;$this->step(20);
        $a=$this->taskPatch(['workChat'=>[...$before['workChat'],['id'=>'MR49/receive/test-cycle/one','text'=>'تم استلام المهمة']]]);
        $this->assertSame('paused',$a['employeeState']);$this->assertSame(0,$a['workTiming']['activeStartedAt']);$this->assertSame(60000,$a['workTiming']['totalMs']);$this->assertSame($this->clock,$a['workTiming']['lastReceivedAt']);
        $this->step(40);$this->taskPatch(['employeeState'=>'working']);$this->step(30);$a=$this->taskPatch(['employeeState'=>'submitted','returnedForRevision'=>false]);
        $this->assertSame(90000,$a['workTiming']['totalMs']);$this->assertCount(2,$a['workTiming']['sessions']);
    }
    public function test_overlong_or_future_legacy_session_never_adds_untrusted_duration(): void
    {
        foreach([$this->clock-90000000,$this->clock+500000] as $start){
            $r=Task::find('T-QA-E1');$d=$r->data;$d['employeeState']='working';$d['workTiming']=['activeStartedAt'=>$start,'totalMs'=>30000,'sessions'=>[]];$r->update(['data'=>$d]);
            $a=$this->taskPatch(['employeeState'=>'paused']);$this->assertSame(30000,$a['workTiming']['totalMs']);$this->assertTrue($a['workTiming']['historyIncomplete']);
            $this->assertCount(0,$a['workTiming']['sessions']);
        }
    }
    public function test_fractional_second_totals_remain_precise(): void
    {
        $r=Task::find('T-QA-E1');$d=$r->data;$d['employeeState']='working';
        $d['workTiming']=['activeStartedAt'=>$this->clock-60000,'total_seconds'=>'30.125','sessions'=>[]];$r->update(['data'=>$d]);
        $a=$this->taskPatch(['employeeState'=>'paused']);$this->assertSame(90125,$a['workTiming']['totalMs']);
        $this->assertArrayNotHasKey('historyIncomplete',$a['workTiming']);
    }
    public function test_repair_missing_total_preserves_a_valid_current_start(): void
    {
        $r=Task::find('T-QA-E1');$d=$r->data;$d['employeeState']='working';
        $d['workTiming']=['activeStartedAt'=>$this->clock-60000,'sessions'=>[
            ['startAt'=>$this->clock-180000,'endAt'=>$this->clock-90000,'workerId'=>'QA-E1']
        ]];$r->update(['data'=>$d]);
        $a=$this->taskPatch(['workTiming'=>['_command'=>'repair_missing']]);
        $this->assertSame($this->clock-60000,$a['workTiming']['activeStartedAt']);
        $this->assertSame(90000,$a['workTiming']['totalMs']);$this->assertTrue($a['workTiming']['historyIncomplete']);
        $a=$this->taskPatch(['employeeState'=>'paused']);$this->assertSame(150000,$a['workTiming']['totalMs']);
    }
    public function test_cancel_closes_running_timer_and_retry_does_not_add_time(): void
    {
        $this->taskPatch(['employeeState'=>'received']);$this->taskPatch(['employeeState'=>'working']);$this->step(60);
        $this->login('qa_admin');$a=$this->taskPatch(['status'=>'cancelled']);
        $this->assertSame(60000,$a['workTiming']['totalMs']);$this->assertSame(0,$a['workTiming']['activeStartedAt']);
        $this->step(120);$a=$this->taskPatch(['status'=>'cancelled']);$this->assertSame(60000,$a['workTiming']['totalMs']);
        $this->assertCount(1,$a['workTiming']['sessions']);$this->assertArrayNotHasKey('historyIncomplete',$a['workTiming']);
    }
    public function test_stale_start_on_paused_task_is_not_counted_as_execution(): void
    {
        $r=Task::find('T-QA-E1');$d=$r->data;$d['employeeState']='paused';
        $d['workTiming']=['activeStartedAt'=>$this->clock-60000,'totalMs'=>30000,'sessions'=>[]];$r->update(['data'=>$d]);
        $a=$this->taskPatch(['notes'=>'Keep paused']);$this->assertSame(30000,$a['workTiming']['totalMs']);
        $this->assertSame(0,$a['workTiming']['activeStartedAt']);$this->assertTrue($a['workTiming']['historyIncomplete']);
        $this->assertCount(0,$a['workTiming']['sessions']);
    }
}


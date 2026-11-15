<?php
namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use App\Models\{Task,Employee,WorkPresence,Setting,AuditEvent};
use App\Support\{WorkdayReport,WorkdayHistory};

/** Run only on an isolated EMPTY test database. Never on restored/user data. */
class WorkdayR8Test extends TestCase
{
    use RefreshDatabase;
    private int $clock;
    protected function setUp(): void
    {
        parent::setUp(); config(['app.timezone'=>'Asia/Damascus']);
        $this->travelTo(Carbon::parse('2026-09-29T09:00:00+03:00'));
        $this->clock=now()->getTimestampMs(); $this->seed();
        $this->postJson('/api/auth/setup',['name'=>'QA Admin','username'=>'qa_admin','password'=>'Test-only-Secret123!','password_confirmation'=>'Test-only-Secret123!'])->assertCreated();
        $this->postJson('/api/customers',['id'=>'QA-C','name'=>'QA Client'])->assertCreated();
        foreach(['QA-E1','QA-E2'] as $id){
            $this->postJson('/api/employees',['id'=>$id,'name'=>$id,'department'=>'التصميم','active'=>true])->assertCreated();
            $this->postJson('/api/users',['name'=>$id,'username'=>strtolower($id),'employeeId'=>$id,'roleId'=>'role_employee','password'=>'Test-only-Secret123!','mustChange'=>false,'active'=>true])->assertCreated();
            $this->postJson('/api/tasks',['id'=>'T-'.$id,'title'=>'Test Task','customer'=>'QA Client','department'=>'التصميم','assigneeId'=>$id,'assignee'=>$id,'status'=>'in_progress','employeeState'=>'new'])->assertCreated();
        }
        $this->login('qa-e1');
    }
    private function login(string $name): void {$this->postJson('/api/auth/logout')->assertOk();$this->postJson('/api/auth/login',['username'=>$name,'password'=>'Test-only-Secret123!'])->assertOk();}
    private function step(int $seconds): void {$this->clock+=$seconds*1000;$this->travelTo(Carbon::createFromTimestampMs($this->clock));}
    private function task(array $p): array {return $this->patchJson('/api/tasks/T-QA-E1',['_version'=>Task::find('T-QA-E1')->version,...$p])->assertOk()->json('data');}
    private function presence(string $mode,bool $noWork=false): array {
        $r=WorkPresence::find('id:QA-E1'); if(!$r && $mode==='break'){$this->presence('active');$r=WorkPresence::find('id:QA-E1');} $p=['mode'=>$mode,'noWork'=>$noWork];
        return ($r?$this->patchJson('/api/work-presence/id:QA-E1',['_version'=>$r->version,...$p]):$this->postJson('/api/work-presence',['id'=>'id:QA-E1',...$p]))->assertSuccessful()->json('data');
    }
    private function report(?string $date=null): array {return $this->getJson('/api/workday'.($date?'?date='.$date:''))->assertOk()->json('data.workday');}
    private function minutes(array $w,string $kind): float|int {
        [$a,$b]=WorkdayReport::bounds($w['date'],$w['timeZone']);$sum=0;
        foreach($w['activities'] as $s)if($s['kind']===$kind)$sum+=max(0,min($b,$s['endAt'])-max($a,$s['startAt']));return $sum/60000;
    }
    public function test_task_break_and_no_work_without_attendance_survive_new_reads_and_login(): void {
        $this->task(['employeeState'=>'received']);$this->task(['employeeState'=>'working']);$this->presence('active');
        $this->step(1800);$this->task(['employeeState'=>'paused']);$this->presence('break');
        $this->step(600);$this->presence('active',true);$this->step(300);$this->presence('off');
        $w=$this->report();$this->assertSame('workday-r8',$w['version']);
        $this->assertSame(30,$this->minutes($w,'task'));$this->assertSame(10,$this->minutes($w,'break'));$this->assertSame(5,$this->minutes($w,'no_work'));
        $this->assertEmpty($w['attendance']);$this->assertEmpty(Employee::find('QA-E1')->data['attendanceRecords']??[]);
        $this->login('qa-e1');$this->assertSame($w,$this->report());
        $this->assertTrue($w['historyPartial']);$this->assertFalse($w['complete']);
    }
    public function test_active_break_continues_using_server_clock(): void {
        $this->presence('break');$this->step(120);$w=$this->report();
        $this->assertSame(2,$this->minutes($w,'break'));$this->assertTrue($w['activities'][0]['open']);
        $this->step(180);$this->assertSame(5,$this->minutes($this->report(),'break'));
    }
    public function test_read_does_not_create_marker_or_change_records(): void {
        Setting::where('id',WorkdayHistory::key('QA-E1'))->delete();
        $before=[Task::find('T-QA-E1')->version,WorkPresence::count(),AuditEvent::count(),Setting::count()];
        $this->report();$this->report();
        $this->assertSame($before,[Task::find('T-QA-E1')->version,WorkPresence::count(),AuditEvent::count(),Setting::count()]);
        $this->assertNull(WorkdayHistory::since('QA-E1'));
    }
    public function test_employee_parameter_cannot_expose_other_employee_data(): void {
        $this->login('qa-e2');$this->patchJson('/api/tasks/T-QA-E2',['_version'=>Task::find('T-QA-E2')->version,'employeeState'=>'received'])->assertOk();
        $this->patchJson('/api/tasks/T-QA-E2',['_version'=>Task::find('T-QA-E2')->version,'employeeState'=>'working'])->assertOk();$this->step(300);
        $this->login('qa-e1');$w=$this->getJson('/api/workday?employeeId=QA-E2')->assertOk()->json('data.workday');
        $this->assertSame('QA-E1',$w['employeeId']);$this->assertEmpty($w['activities']);
    }
    public function test_unauthenticated_request_is_rejected_and_unlinked_admin_has_no_personal_report(): void {
        $this->postJson('/api/auth/logout')->assertOk();$this->getJson('/api/workday')->assertUnauthorized();
        \App\Models\User::where('username','qa_admin')->update(['employee_id'=>null]);
        $this->postJson('/api/auth/login',['username'=>'qa_admin','password'=>'Test-only-Secret123!'])->assertOk();
        $this->getJson('/api/workday')->assertOk()->assertJsonPath('data.workday',null);
    }
    public function test_invalid_and_future_dates_are_rejected(): void {
        foreach(['2026-02-30','2026-09-30','not-a-date'] as $date)$this->getJson('/api/workday?date='.$date)->assertUnprocessable();
        $this->assertSame('2026-09-29',$this->report()['date']);
    }
    public function test_reassigned_task_retains_only_my_historical_intervals(): void {
        $this->task(['employeeState'=>'received']);$this->task(['employeeState'=>'working']);$this->step(600);$this->task(['employeeState'=>'paused']);
        $r=Task::find('T-QA-E1');$d=$r->data;$d['assigneeId']='QA-E2';$d['assignee']='QA-E2';
        $d['workTiming']['sessions'][]=['workerId'=>'QA-E2','startAt'=>$this->clock,'endAt'=>$this->clock+300000];
        $r->update(['data'=>$d,'employee_id'=>'QA-E2']);$this->step(300);$w=$this->report();
        $this->assertSame(10,$this->minutes($w,'task'));$this->assertCount(1,$w['activities']);
    }
    public function test_deleted_task_time_is_read_from_audit_without_recreating_task(): void {
        $this->task(['employeeState'=>'received']);$this->task(['employeeState'=>'working']);$this->step(180);$this->task(['employeeState'=>'paused']);
        $this->login('qa_admin');$r=Task::find('T-QA-E1');$this->deleteJson('/api/tasks/T-QA-E1',['_version'=>$r->version])->assertOk();
        $this->login('qa-e1');$this->assertSame(3,$this->minutes($this->report(),'task'));$this->assertNull(Task::find('T-QA-E1'));
    }
    public function test_duplicate_transaction_does_not_repeat_break_or_marker(): void {
        $this->presence('break');$marker=WorkdayHistory::since('QA-E1');$this->step(600);
        $body=['operations'=>[['resource'=>'work-presence','id'=>'id:QA-E1','action'=>'update','version'=>WorkPresence::find('id:QA-E1')->version,'data'=>['mode'=>'active','noWork'=>true]]]];
        $this->withHeader('Idempotency-Key','r8-identical-transition')->postJson('/api/transactions',$body)->assertOk();
        $this->step(60);$this->withHeader('Idempotency-Key','r8-identical-transition')->postJson('/api/transactions',$body)->assertOk();
        $w=$this->report();$this->assertSame(10,$this->minutes($w,'break'));$this->assertSame(1,$this->minutes($w,'no_work'));$this->assertSame($marker,WorkdayHistory::since('QA-E1'));
    }
    public function test_failed_transaction_does_not_create_history_marker(): void {
        Setting::where('id',WorkdayHistory::key('QA-E1'))->delete();
        $ops=[['resource'=>'work-presence','id'=>'id:QA-E1','action'=>'create','data'=>['mode'=>'active']],
              ['resource'=>'tasks','id'=>'T-QA-E2','action'=>'update','version'=>Task::find('T-QA-E2')->version,'data'=>['employeeState'=>'working']]];
        $this->withHeader('Idempotency-Key','r8-rollback')->postJson('/api/transactions',['operations'=>$ops])->assertForbidden();
        $this->assertNull(WorkdayHistory::since('QA-E1'));$this->assertSame(0,WorkPresence::count());
    }
    public function test_no_work_cannot_remain_active_after_off_or_break(): void {
        $this->presence('active',true);$this->step(60);$a=$this->presence('off',true);$this->assertFalse($a['noWork']);
        $this->step(60);$this->assertSame(1,$this->minutes($this->report(),'no_work'));
    }
    public function test_midnight_is_split_in_company_timezone(): void {
        $this->travelTo(Carbon::parse('2026-09-28T23:50:00+03:00'));$this->clock=now()->getTimestampMs();
        $this->presence('break');$this->step(1800);$this->presence('off');
        $this->assertSame(10,$this->minutes($this->report('2026-09-28'),'break'));
        $this->assertSame(20,$this->minutes($this->report('2026-09-29'),'break'));
    }
    public function test_task_crossing_midnight_is_split_and_not_its_lifetime_total(): void {
        $this->travelTo(Carbon::parse('2026-09-28T23:50:00+03:00'));$this->clock=now()->getTimestampMs();
        $this->task(['employeeState'=>'received']);$this->task(['employeeState'=>'working']);$this->step(1800);$this->task(['employeeState'=>'paused']);
        $this->assertSame(10,$this->minutes($this->report('2026-09-28'),'task'));$this->assertSame(20,$this->minutes($this->report('2026-09-29'),'task'));
    }
    public function test_legacy_missing_and_overlong_times_are_not_fabricated(): void {
        $r=Task::find('T-QA-E1');$d=$r->data;$d['employeeState']='working';$d['workTiming']=['activeStartedAt'=>0,'activeWorkerId'=>'QA-E1','totalMs'=>9999999];$r->update(['data'=>$d]);
        $w=$this->report();$this->assertEmpty($w['activities']);$this->assertNotEmpty($w['issues']);$this->assertFalse($w['complete']);
        $d['workTiming']['activeStartedAt']=$this->clock-90000000;$r->update(['data'=>$d]);$this->assertEmpty($this->report()['activities']);
    }
    public function test_coverage_is_complete_only_after_known_tracking_start(): void {
        $this->presence('active');$this->assertFalse($this->report()['complete']);
        $this->travelTo(Carbon::parse('2026-09-30T09:00:00+03:00'));$this->clock=now()->getTimestampMs();
        $w=$this->report();$this->assertTrue($w['complete']);$this->assertEmpty($w['attendance']);$this->assertEmpty($w['activities']);
    }
}

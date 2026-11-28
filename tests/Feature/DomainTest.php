<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Storage,Mail,DB};
use App\Models\{User,Employee,Setting,Warehouse,StockMovement,Payment,JournalEntry,HrRequest,FinancialDocument};
class DomainTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void {parent::setUp();$this->seed();$this->postJson('/api/auth/setup',['name'=>'Admin','username'=>'admin','password'=>'Secure-test123!','password_confirmation'=>'Secure-test123!'])->assertCreated();foreach(['Client','Customer'] as $name)$this->postJson('/api/customers',['name'=>$name])->assertCreated();}
    private function employee(string $id,string $department='التصميم'): array {return $this->postJson('/api/employees',['id'=>$id,'name'=>$id,'department'=>$department,'active'=>true,'hireDate'=>'2025-01-01','jobTitle'=>'QA role','attendanceMode'=>'fingerprint','workdayHours'=>8,'zktecoEnrollmentNumber'=>(string)(2000+Employee::count())])->assertCreated()->json('data');}
    private function user(string $id,string $role='role_employee',array $extra=[]): array {return $this->postJson('/api/users',array_merge(['name'=>$id,'username'=>strtolower($id),'employeeId'=>$id,'roleId'=>$role,'password'=>'Secure-test123!','active'=>true,'mustChange'=>false],$extra))->assertCreated()->json('data');}
    private function login(string $username): void {$this->postJson('/api/auth/logout')->assertOk();$this->postJson('/api/auth/login',['username'=>strtolower($username),'password'=>'Secure-test123!'])->assertOk();}
    public function test_all_resource_lists_settings_and_reports_are_live(): void {
        foreach (array_keys(config('mosh.spec.resources')) as $resource) $this->getJson('/api/'.$resource.'?perPage=1')->assertOk()->assertJsonStructure(['success','data','meta'=>['total','lastPage']]);
        foreach (array_keys(config('mosh.spec.settings')) as $key) $this->getJson('/api/settings/'.$key)->assertOk();
        foreach (['sales','design-work','hr','operations','inventory','finance','cash','trial-balance','party-statement','payroll'] as $report) $this->getJson('/api/reports/'.$report.'?currency=USD&perPage=1')->assertOk()->assertJsonStructure(['data'=>['rows','summary']]);
        $this->postJson('/api/quotations',['id'=>'Q-SALES','customer'=>'Client','lines'=>[['type'=>'product','product'=>'Work','qty'=>2,'price'=>10.25]]])->assertCreated();
        $this->getJson('/api/reports/sales?currency=USD')->assertOk()->assertJsonPath('data.summary.amountByCurrency.USD',20.5);
        $this->getJson('/api/reports/trial-balance?currency=USD&perPage=0')->assertUnprocessable();
        $this->getJson('/api/dashboard')->assertOk();$this->getJson('/api/departments/'.rawurlencode('التصميم').'/dashboard')->assertOk();
    }
    public function test_task_scope_and_own_presence_cannot_be_bypassed(): void {
        $this->employee('DESIGN1');$this->employee('DESIGN2');$this->employee('SALES1','المبيعات');$this->user('DESIGN1','role_manager',['taskScope'=>'mine']);
        foreach (['DESIGN1','DESIGN2','SALES1'] as $id) $this->postJson('/api/tasks',['id'=>'TASK-'.$id,'title'=>'Task '.$id,'customer'=>'Customer','department'=>$id==='SALES1'?'المبيعات':'التصميم','assignee'=>$id,'assigneeId'=>$id,'status'=>'in_progress'])->assertCreated();
        $this->login('DESIGN1');
        $this->getJson('/api/tasks?scope=all')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.assigneeId','DESIGN1');
        $this->getJson('/api/tasks/TASK-DESIGN2')->assertNotFound();
        $this->postJson('/api/work-presence',['id'=>'id:DESIGN2','mode'=>'active'])->assertForbidden();
        $this->postJson('/api/work-presence',['id'=>'id:DESIGN1','mode'=>'active'])->assertCreated()->assertJsonPath('data.employeeId','DESIGN1');
        $this->putJson('/api/settings/finance',['value'=>['lockedThrough'=>'2026-08-31'],'_version'=>1])->assertForbidden();
    }
    public function test_hr_stages_cannot_be_skipped_and_effect_is_applied_once(): void {
        $this->employee('WORKER');$r=$this->postJson('/api/hr-requests',['employeeId'=>'WORKER','type'=>'annual_leave','reason'=>'Leave','data'=>['startDate'=>now(config('app.timezone'))->addDay()->toDateString(),'endDate'=>now(config('app.timezone'))->addDays(2)->toDateString()]])->assertCreated()->json('data');
        $url='/api/hr-requests/'.$r['id'];
        $this->patchJson($url,['_version'=>$r['_version'],'status'=>'approved'])->assertUnprocessable();
        while($r['status']==='pending'){$last=$r['stageIndex']===count($r['route'])-1;$r=$this->patchJson($url,['_version'=>$r['_version'],'stageIndex'=>$last?$r['stageIndex']:$r['stageIndex']+1,'status'=>$last?'approved':'pending'])->assertOk()->json('data');}
        $this->patchJson($url,['_version'=>$r['_version'],'status'=>'approved'])->assertOk();
        $this->assertCount(1,Employee::find('WORKER')->data['leaveRecords']);
        $this->user('WORKER');$this->login('WORKER');
        $this->postJson('/api/hr-requests',['employeeId'=>User::where('is_root',true)->first()->employee_id,'type'=>'annual_leave','reason'=>'Impersonation','data'=>['startDate'=>'2026-11-01','endDate'=>'2026-11-02']])->assertForbidden();
    }
    public function test_new_leave_starts_on_a_current_or_future_date_and_is_charged_only_once_approved(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-28 10:30:00','Asia/Damascus'));
        config(['app.timezone'=>'Asia/Damascus']);
        $this->employee('LEAVE-WORKER');
        $this->user('LEAVE-WORKER');
        $this->login('LEAVE-WORKER');
        $base = ['employeeId'=>'LEAVE-WORKER','reason'=>'Leave fixture'];
        foreach (['annual_leave','sick_leave'] as $type) {
            $this->postJson('/api/hr-requests', $base + [
                'type'=>$type,'data'=>['startDate'=>'2026-11-27','endDate'=>'2026-11-28'],
            ])->assertUnprocessable()->assertJsonPath('message','اختر تاريخ إجازة من اليوم فما بعد.');
        }
        foreach (['2026-11-27','2026-11-28'] as $date) {
            $this->postJson('/api/hr-requests', $base + [
                'type'=>'hourly_leave','data'=>['date'=>$date,'fromTime'=>'09:00','toTime'=>'10:00'],
            ])->assertUnprocessable();
        }
        $this->assertDatabaseCount('hr_requests',0);
        $this->assertDatabaseCount('payroll_hours',0);
        $this->assertSame([],Employee::find('LEAVE-WORKER')->data['leaveRecords']??[]);

        $this->postJson('/api/hr-requests', $base + [
            'type'=>'hourly_leave','data'=>['date'=>'2026-11-28','fromTime'=>'11:00','toTime'=>'12:00'],
        ])->assertCreated()->assertJsonPath('data.status','pending');
        $request = $this->postJson('/api/hr-requests', $base + [
            'type'=>'annual_leave','data'=>['startDate'=>'2026-11-28','endDate'=>'2026-11-28'],
        ])->assertCreated()->json('data');
        $this->assertSame([],Employee::find('LEAVE-WORKER')->data['leaveRecords']??[]);

        $this->login('admin');
        $employee = Employee::find('LEAVE-WORKER');
        $this->patchJson('/api/employees/LEAVE-WORKER', [
            '_version'=>$employee->version,'leaveRecords'=>[['id'=>'NEW-PAST','type'=>'سنوية','status'=>'approved','startDate'=>'2026-11-27','endDate'=>'2026-11-27']],
        ])->assertUnprocessable();
        $this->assertSame([],Employee::find('LEAVE-WORKER')->data['leaveRecords']??[]);

        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-29 10:30:00','Asia/Damascus'));
        $url = '/api/hr-requests/'.$request['id'];
        while ($request['status'] === 'pending') {
            $last = $request['stageIndex'] === count($request['route']) - 1;
            $request = $this->patchJson($url, [
                '_version'=>$request['_version'],'stageIndex'=>$last?$request['stageIndex']:$request['stageIndex']+1,
                'status'=>$last?'approved':'pending',
            ])->assertOk()->json('data');
        }
        $employee->refresh();
        $leaves = $employee->data['leaveRecords'];
        $this->assertCount(1,$leaves);
        $this->assertSame('2026-11-28',$leaves[0]['startDate']);
        $this->assertSame($leaves[0]['startDate'],$leaves[0]['endDate']);
        $this->patchJson('/api/employees/LEAVE-WORKER', [
            '_version'=>$employee->version,'notes'=>'Unrelated profile edit',
        ])->assertOk()->assertJsonPath('data.leaveRecords.0.status','approved');
    }

    public function test_employee_can_confirm_and_read_own_leave_when_hr_module_is_hidden(): void
    {
        $this->employee('LEAVE-OWNER');
        $this->employee('OTHER-LEAVE');
        $this->user('LEAVE-OWNER');
        $owner=User::where('employee_id','LEAVE-OWNER')->firstOrFail();
        $owner->update(['data'=>array_replace_recursive($owner->data, [
            'moduleOverrides'=>['hr'=>['view'=>false,'list'=>false,'details'=>false,'create'=>false,'approve'=>false]],
        ])]);

        $date=now(config('app.timezone'))->addDay()->toDateString();
        $leave=['type'=>'annual_leave','reason'=>'One day','data'=>['startDate'=>$date,'endDate'=>$date]];
        $other=$this->postJson('/api/hr-requests',$leave+['employeeId'=>'OTHER-LEAVE'])
            ->assertCreated()->json('data.id');

        $this->login('LEAVE-OWNER');
        $payload=$leave+['id'=>'HRR/own-leave-receipt','employeeId'=>'LEAVE-OWNER'];
        $headers=['Idempotency-Key'=>'own-leave-receipt'];
        $path='/api/hr-requests/'.rawurlencode($payload['id']);
        $this->postJson('/api/hr-requests',$payload,$headers)->assertCreated()
            ->assertJsonPath('data.id',$payload['id'])->assertJsonPath('data._version',1)
            ->assertJsonPath('data.status','pending');
        $this->getJson($path)->assertOk()->assertJsonPath('data.employeeId','LEAVE-OWNER');
        $this->getJson('/api/hr-requests')->assertOk()->assertJsonCount(1,'data')
            ->assertJsonPath('data.0.id',$payload['id']);
        $this->getJson('/api/bootstrap?section=employeePortal&tasks=omit')->assertOk()
            ->assertJsonCount(1,'data.state.hrProRequests')->assertJsonPath('data.state.hrProRequests.0.id',$payload['id']);
        $this->getJson('/api/hr-requests/'.rawurlencode($other))->assertNotFound();
        $this->postJson('/api/hr-requests',$leave+['employeeId'=>'OTHER-LEAVE'])->assertForbidden();
        $this->patchJson($path,['_version'=>1,'status'=>'approved'])->assertForbidden();

        // Before the fix, saving completed but the cached response omitted the request.
        DB::table('transactions')->where('actor_id',$owner->id)->where('idempotency_key','own-leave-receipt')
            ->update(['response'=>json_encode(['changes'=>[]], JSON_THROW_ON_ERROR)]);
        $this->postJson('/api/hr-requests',$payload,$headers)->assertCreated()
            ->assertJsonPath('data.id',$payload['id'])->assertJsonPath('data._version',1)
            ->assertJsonPath('data.status','pending');
        $this->assertDatabaseCount('hr_requests',2);
    }

    public function test_named_manager_can_receive_and_forward_leave_with_hr_module_hidden(): void
    {
        foreach (['LEAVE-REQUESTER','LEAVE-MANAGER','OTHER-MANAGER'] as $id) {
            $this->employee($id,'تكنولوجيا المعلومات');
            $this->user($id,$id==='LEAVE-REQUESTER'?'role_employee':'role_manager');
        }
        $overrides=['hr'=>['view'=>false,'list'=>false,'details'=>false,'edit'=>false,'approve'=>false]];
        foreach (['LEAVE-MANAGER','OTHER-MANAGER'] as $id) {
            $user=User::where('employee_id',$id)->firstOrFail();
            $user->update(['data'=>array_replace_recursive($user->data,['moduleOverrides'=>$overrides])]);
        }
        \App\Support\R19Store::put(\App\Services\R34\Hierarchy::KEY,[
            'enabled'=>true,'generalManagerId'=>'LEAVE-MANAGER',
            'reportsTo'=>['LEAVE-REQUESTER'=>'LEAVE-MANAGER','OTHER-MANAGER'=>'LEAVE-MANAGER'],
        ],0);

        $date=now(config('app.timezone'))->addDay()->toDateString();
        $this->login('LEAVE-REQUESTER');
        $row=$this->postJson('/api/hr-requests',[
            'id'=>'HRR/named-manager-qa','employeeId'=>'LEAVE-REQUESTER','type'=>'annual_leave',
            'reason'=>'One day','data'=>['startDate'=>$date,'endDate'=>$date],
        ])->assertCreated()->json('data');
        $path='/api/hr-requests/'.rawurlencode($row['id']);
        $advance=['_version'=>$row['_version'],'stageIndex'=>1,'managerDecision'=>'approved'];

        $this->login('OTHER-MANAGER');
        $this->getJson('/api/hr-requests')->assertOk()->assertJsonCount(0,'data');
        $this->getJson($path)->assertNotFound();
        $this->patchJson($path,$advance)->assertForbidden();

        $this->login('LEAVE-MANAGER');
        $this->getJson('/api/bootstrap?section=it')->assertOk()
            ->assertJsonPath('data.user.moduleOverrides.hr.view',false)
            ->assertJsonPath('data.state.hrProRequests.0.id',$row['id']);
        $this->getJson($path)->assertOk()->assertJsonPath('data.stageIndex',0);
        $this->patchJson($path,$advance)->assertOk();
        $this->getJson($path)->assertNotFound();

        $this->login('admin');
        $this->getJson($path)->assertOk()->assertJsonPath('data.status','pending')
            ->assertJsonPath('data.stageIndex',1)->assertJsonPath('data.route.1','الموارد البشرية')
            ->assertJsonPath('data.managerDecision','approved');
    }

    public function test_hr_inbox_receives_leave_after_manager_and_allows_final_decision(): void
    {
        foreach (['LEAVE-STAFF','LEAVE-LEAD','HR-REVIEWER','HR-READER'] as $id) {
            $hr=str_starts_with($id,'HR-');
            $this->employee($id,$hr?'الموارد البشرية':'تكنولوجيا المعلومات');
            $this->user($id,$id==='LEAVE-STAFF'?'role_employee':'role_manager');
        }
        $reader=User::where('employee_id','HR-READER')->firstOrFail();
        $reader->update(['data'=>array_replace_recursive($reader->data,['moduleOverrides'=>['hr'=>['approve'=>false]]])]);
        \App\Support\R19Store::put(\App\Services\R34\Hierarchy::KEY,[
            'enabled'=>true,'generalManagerId'=>'LEAVE-LEAD','reportsTo'=>['LEAVE-STAFF'=>'LEAVE-LEAD'],
        ],0);

        $date=now(config('app.timezone'))->addDay()->toDateString();
        $this->login('LEAVE-STAFF');
        $leave=$this->postJson('/api/hr-requests',[
            'employeeId'=>'LEAVE-STAFF','type'=>'annual_leave','reason'=>'One day',
            'data'=>['startDate'=>$date,'endDate'=>$date],
        ])->assertCreated()->json('data');
        $inbox='/api/r34/approvals';
        $endpoint=$inbox.'/hr';
        $command=['resource'=>'hr-requests','id'=>$leave['id'],'action'=>'approve','_version'=>$leave['_version']];
        $this->login('HR-REVIEWER');
        $this->getJson($inbox)->assertOk()->assertJsonPath('data.pendingCount',0);
        $this->postJson($endpoint,$command+['requestId'=>'wrong-hr-stage'])->assertForbidden();

        $this->login('LEAVE-LEAD');
        $lead=$this->postJson($endpoint,$command+['requestId'=>'lead-forwards-leave'])->assertOk()
            ->assertJsonPath('data.stageIndex',1)->assertJsonPath('data.managerDecision','approved')
            ->assertJsonPath('data.managerDecisionBy','LEAVE-LEAD')->json('data');

        $this->login('HR-READER');
        $this->getJson($inbox)->assertOk()->assertJsonCount(0,'data.hr');
        $final=array_replace($command,['_version'=>$lead['_version']]);
        $this->postJson($endpoint,$final+['requestId'=>'read-only-cannot-decide'])->assertForbidden();

        $this->login('HR-REVIEWER');
        $this->getJson($inbox)->assertOk()->assertJsonPath('data.pendingCount',1)
            ->assertJsonPath('data.hr.0.id',$leave['id'])->assertJsonPath('data.hr.0.canDecide',true);
        $final+=['requestId'=>'hr-final-leave-approval'];
        foreach ([1,2] as $attempt) {
            $this->postJson($endpoint,$final)->assertOk()->assertJsonPath('data.status','approved')
                ->assertJsonPath('data.managerDecisionBy','LEAVE-LEAD');
        }
        $this->getJson($inbox)->assertOk()->assertJsonPath('data.pendingCount',0);
        $this->assertCount(1,Employee::findOrFail('LEAVE-STAFF')->data['leaveRecords']);
    }

    public function test_private_uploads_require_link_access_and_cannot_disappear_while_linked(): void {
        Storage::fake('local');$this->employee('WORKER');$this->user('WORKER');
        $file=UploadedFile::fake()->createWithContent('secret.txt','Sensitive document');
        $this->post('/api/files',['file'=>$file,'key'=>'FILE/PRIVATE'],['Accept'=>'application/json'])->assertCreated();
        $root=Employee::where('department','الإدارة')->first();
        $this->patchJson('/api/employees/'.$root->id,['_version'=>$root->version,'documents'=>[['key'=>'FILE/PRIVATE','name'=>'secret.txt']]])->assertOk();
        $this->deleteJson('/api/files/FILE%2FPRIVATE')->assertConflict();
        $this->login('WORKER');$this->getJson('/api/files/FILE%2FPRIVATE')->assertNotFound();
        $this->login('admin');$root->refresh();$this->patchJson('/api/employees/'.$root->id,['_version'=>$root->version,'documents'=>[]])->assertOk();
        $this->deleteJson('/api/files/FILE%2FPRIVATE')->assertOk();Storage::disk('local')->assertDirectoryEmpty('attachments/'.User::where('is_root',true)->value('id'));
    }
    public function test_stock_shortage_rolls_back_and_custody_is_derived(): void {
        $this->employee('WORKER');$wh=Warehouse::first()->id;
        $this->postJson('/api/stock-items',['id'=>'CAM','name'=>'Camera','tracking'=>'returnable','owner'=>'company'])->assertCreated();
        $this->postJson('/api/stock-movements',['id'=>'RCV','type'=>'receipt','itemId'=>'CAM','qty'=>2,'to'=>$wh,'date'=>'2026-01-01','reason'=>'Opening'])->assertCreated();
        $this->postJson('/api/stock-movements',['type'=>'issue','itemId'=>'CAM','qty'=>3,'from'=>$wh,'employeeId'=>'WORKER','date'=>'2026-01-02'])->assertUnprocessable();
        $this->assertSame(1,StockMovement::count());
        $this->postJson('/api/stock-movements',['id'=>'ISSUE','type'=>'issue','itemId'=>'CAM','qty'=>1,'from'=>$wh,'employeeId'=>'WORKER','date'=>'2026-01-02'])->assertCreated();
        $this->assertSame('warehouse18',Employee::find('WORKER')->data['assets'][0]['source']);
        $this->patchJson('/api/stock-movements/ISSUE',['_version'=>1,'qty'=>2])->assertUnprocessable();
        $this->postJson('/api/stock-movements',['type'=>'return','itemId'=>'CAM','qty'=>1,'to'=>$wh,'employeeId'=>'WORKER','refMoveId'=>'ISSUE','date'=>'2026-01-03'])->assertCreated();
        $this->assertSame(2.0,\App\Support\StockRules::balance('CAM',$wh));
    }
    public function test_financial_overpayment_and_forged_totals_do_not_commit(): void {
        $this->postJson('/api/customers',['id'=>'C1','name'=>'Finance Client'])->assertCreated();
        $doc=$this->postJson('/api/invoices',['id'=>'I1','customer'=>'Finance Client','number'=>'INV-1','finance21'=>['id'=>'INV:I1','kind'=>'customer','partyId'=>'C1','currency'=>'USD','date'=>'2026-01-01','dueDate'=>'2026-01-31','status'=>'draft','total'=>1,'lines'=>[['name'=>'Work','qty'=>2,'price'=>'10.25']]]])->assertCreated()->json('data');
        $this->assertSame(2050,$doc['finance21']['total']);
        $f=$doc['finance21'];$f['status']='posted';$this->patchJson('/api/invoices/I1',['_version'=>1,'finance21'=>$f])->assertOk();
        $this->postJson('/api/cash-accounts',['id'=>'CASH1','name'=>'Cash','currency'=>'USD','type'=>'cash','openingDate'=>'2026-01-01','opening'=>0])->assertCreated();
        $body=['kind'=>'receipt','currency'=>'USD','date'=>'2026-01-02','amount'=>2051,'reference'=>'R1','docId'=>'INV:I1','accountId'=>'CASH1'];
        $this->postJson('/api/payments',$body)->assertUnprocessable();$this->assertSame(0,Payment::count());
        $body['amount']=1000;$this->postJson('/api/payments',$body)->assertCreated();
        $this->postJson('/api/payments',$body)->assertUnprocessable();$this->assertSame(1,Payment::count());
        $this->assertSame(2,JournalEntry::count());
        $this->patchJson('/api/customers/C1',['_version'=>1,'name'=>'Renamed Client'])->assertOk();
        $this->getJson('/api/invoices/I1')->assertJsonPath('data.customer','Renamed Client');
    }
    public function test_contract_signatures_lock_terms(): void {
        $this->employee('WORKER');
        $d=$this->postJson('/api/employment-contracts',['id'=>'CON1','employeeId'=>'WORKER','employeeName'=>'WORKER','company'=>'MOSH','jobTitle'=>'Designer','type'=>'دائم','startDate'=>'2026-01-01','salary'=>1000,'workHours'=>8])->assertCreated()->json('data');
        $sig=['employer'=>['name'=>'Admin','strokes'=>[[[1,1],[2,3],[4,5]]]]];
        $this->patchJson('/api/employment-contracts/CON1',['_version'=>1,'signatures'=>$sig])->assertOk()->assertJsonPath('data.status','pending_signature');
        $this->patchJson('/api/employment-contracts/CON1',['_version'=>2,'salary'=>900])->assertUnprocessable();
        $this->deleteJson('/api/employment-contracts/CON1',['_version'=>2])->assertUnprocessable();
    }
    public function test_user_account_normalization_forced_password_and_permission_limits(): void {
        $this->employee('ALICE');$u=$this->user('ALICE','role_employee',['mustChange'=>true]);
        $this->postJson('/api/users',['name'=>'Duplicate','username'=>'ALICE','password'=>'Secure-test123!','roleId'=>'role_employee','active'=>true])->assertUnprocessable();
        $this->login('ALICE');$this->getJson('/api/customers')->assertForbidden();
        $this->patchJson('/api/auth/password',['current_password'=>'Secure-test123!','password'=>'New-secure123!','password_confirmation'=>'New-secure123!'])->assertOk();
        $this->putJson('/api/users/'.$u['id'].'/permissions',['_version'=>1,'globalOverrides'=>['manageUsers'=>true],'moduleOverrides'=>[]])->assertForbidden();
    }
    public function test_email_submission_is_idempotent_without_sending_live_mail(): void {
        Mail::fake();$this->postJson('/api/quotations',['id'=>'Q1','customer'=>'Client','status'=>'draft'])->assertCreated();
        $body=['to'=>'customer@example.com','subject'=>'Quote','body'=>'Quotation details'];
        $a=$this->withHeader('Idempotency-Key','email-test-key')->postJson('/api/quotations/Q1/email',$body)->assertOk()->json();
        $b=$this->withHeader('Idempotency-Key','email-test-key')->postJson('/api/quotations/Q1/email',$body)->assertOk()->json();$this->assertSame($a,$b);
    }
    public function test_department_manager_does_not_inherit_private_contract_access(): void {
        $this->employee('MANAGER');$this->employee('WORKER');$this->user('MANAGER','role_manager');
        $this->putJson('/api/settings/department-managers',['_version'=>1,'value'=>['التصميم'=>'MANAGER']])->assertOk();
        $this->postJson('/api/employment-contracts',['id'=>'PRIVATE','employeeId'=>'WORKER','employeeName'=>'WORKER','company'=>'MOSH','jobTitle'=>'Designer','type'=>'دائم','startDate'=>'2026-01-01','salary'=>1000,'workHours'=>8])->assertCreated();
        $this->login('MANAGER');$this->getJson('/api/employment-contracts/PRIVATE')->assertNotFound();
    }
    public function test_explicit_denied_task_status_is_enforced_on_server(): void {
        $this->employee('WORKER');$u=$this->user('WORKER');
        $this->putJson('/api/users/'.$u['id'].'/permissions',['_version'=>1,'globalOverrides'=>[],'moduleOverrides'=>['design'=>['status'=>false]]])->assertOk();
        $this->postJson('/api/tasks',['id'=>'T1','title'=>'Task','customer'=>'Client','department'=>'التصميم','assignee'=>'WORKER','assigneeId'=>'WORKER','status'=>'in_progress','employeeState'=>'new'])->assertCreated();
        $this->login('WORKER');$this->patchJson('/api/tasks/T1',['_version'=>1,'employeeState'=>'received'])->assertForbidden();
    }

    public function test_delegated_account_manager_cannot_grant_other_department_access(): void {
        $this->employee('MANAGER');$this->employee('HR1','الموارد البشرية');$u=$this->user('MANAGER','role_manager');
        $this->putJson('/api/users/'.$u['id'].'/permissions',['_version'=>1,'globalOverrides'=>['manageUsers'=>true],'moduleOverrides'=>[]])->assertOk();
        $this->login('MANAGER');
        $this->postJson('/api/users',['name'=>'HR1','username'=>'hr1','employeeId'=>'HR1','roleId'=>'role_employee','password'=>'Secure-test123!','active'=>true])->assertForbidden();
    }
    public function test_arabic_decimal_entry_matches_the_vanilla_finance_controls(): void {
        $this->assertSame(123450,\App\Support\Money::minor('١٢٣٤٫٥٠','USD'));
        $this->assertSame(123450,\App\Support\Money::minor('۱۲۳۴٫۵۰','USD'));
    }

}

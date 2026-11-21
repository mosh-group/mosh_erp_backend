<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Storage,Mail};
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
        $this->employee('WORKER');$r=$this->postJson('/api/hr-requests',['employeeId'=>'WORKER','type'=>'annual_leave','reason'=>'Leave','data'=>['startDate'=>'2026-10-01','endDate'=>'2026-10-02']])->assertCreated()->json('data');
        $url='/api/hr-requests/'.$r['id'];
        $this->patchJson($url,['_version'=>$r['_version'],'status'=>'approved'])->assertUnprocessable();
        while($r['status']==='pending'){$last=$r['stageIndex']===count($r['route'])-1;$r=$this->patchJson($url,['_version'=>$r['_version'],'stageIndex'=>$last?$r['stageIndex']:$r['stageIndex']+1,'status'=>$last?'approved':'pending'])->assertOk()->json('data');}
        $this->patchJson($url,['_version'=>$r['_version'],'status'=>'approved'])->assertOk();
        $this->assertCount(1,Employee::find('WORKER')->data['leaveRecords']);
        $this->user('WORKER');$this->login('WORKER');
        $this->postJson('/api/hr-requests',['employeeId'=>User::where('is_root',true)->first()->employee_id,'type'=>'annual_leave','reason'=>'Impersonation','data'=>['startDate'=>'2026-11-01','endDate'=>'2026-11-02']])->assertForbidden();
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

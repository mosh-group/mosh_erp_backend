<?php
namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Storage,DB};
use Illuminate\Http\UploadedFile;
use App\Models\{User,Role,Employee,Setting,Customer,Task,Quotation,Warehouse,StockItem,StockMovement,Reservation,SupportRequest,FinancialDocument,PayrollHours,PayrollRun,JournalEntry};
use App\Support\{Records,Money};

class BackendAuditTest extends TestCase
{
    use RefreshDatabase;
    private User $root;
    protected function setUp(): void {
        parent::setUp();$this->seed();
        $this->postJson('/api/auth/setup',['name'=>'Admin','username'=>'admin','password'=>'Secure-test123!','password_confirmation'=>'Secure-test123!'])->assertCreated();
        $this->root=User::where('is_root',true)->first();
        $this->create('customers',['id'=>'CLIENT','name'=>'Client']);
    }
    private function create(string $resource,array $data): array {return $this->postJson('/api/'.$resource,$data)->assertCreated()->json('data');}
    private function change(string $resource,string $id,array $data) {return $this->patchJson('/api/'.$resource.'/'.rawurlencode($id),['_version'=>Records::find($resource,$id)->version]+$data);}
    private function employee(string $id='WORKER',string $department='التصميم',array $extra=[]): array {return $this->create('employees',array_replace(['id'=>$id,'name'=>$id,'department'=>$department,'active'=>true,'jobTitle'=>'QA role','hireDate'=>'2025-01-01','attendanceMode'=>'fingerprint','workdayHours'=>8,'zktecoEnrollmentNumber'=>(string)(1000+Employee::count())],$extra));}
    private function actor(array $modules=[],array $extra=[],string $scope='custom'): User {
        $id='EMP'.(Employee::count()+1);$this->employee($id,$extra['department']??'التصميم');
        $role=Role::create(['id'=>'ROLE'.$id,'name'=>$id,'data'=>['scope'=>$scope,'taskScope'=>'all','modules'=>$modules,'globals'=>[],'scopeActions'=>[]],'version'=>1]);
        return User::create(['id'=>'USR'.$id,'name'=>$id,'username'=>strtolower($id),'password'=>'Secure-test123!','employee_id'=>$id,'department'=>$extra['department']??'التصميم','role_id'=>$role->id,'active'=>true,'is_root'=>false,'must_change'=>false,'auth_version'=>1,'version'=>1,'data'=>$extra['data']??[]]);
    }
    private function as(User $u): void {$this->actingAs($u->fresh())->withSession(['auth_version'=>$u->fresh()->auth_version,'last_activity'=>time()]);}
    private function batch(array $ops,string $key='audit-transaction-123') {return $this->postJson('/api/transactions',['operations'=>$ops],['Idempotency-Key'=>$key]);}
    private function setting(string $key,array $value) {return $this->putJson('/api/settings/'.$key,['_version'=>Setting::findOrFail($key)->version,'value'=>$value]);}
    private function task(array $extra=[]): array {return $this->create('tasks',array_replace(['id'=>'TASK','title'=>'Work','customer'=>'Client','department'=>'التصميم','status'=>'in_progress','employeeState'=>'new'],$extra));}
    private function contract(array $extra=[]): array {return $this->create('employment-contracts',array_replace(['id'=>'CONTRACT','employeeId'=>'WORKER','employeeName'=>'WORKER','company'=>'MOSH','jobTitle'=>'Designer','type'=>'دائم','startDate'=>'2026-01-01','salary'=>500,'workHours'=>8],$extra));}
    private function layout(array $extra=[]): array {return array_replace(['name'=>'Layout','family'=>'invoice','font'=>'Arial','size'=>12,'width'=>210,'height'=>297,'margin'=>14,'padding'=>9,'color'=>'#111111','line'=>'#cccccc','headBg'=>'#eeeeee','blocks'=>[]],$extra);}
    private function hours(string $id='HOURS'): array {
        return $this->create('payroll-hours',['id'=>$id,'employeeId'=>'WORKER','period'=>'2026-01','currency'=>'USD','basis'=>'monthly','baseAmount'=>'500','normHours'=>'160','regularHours'=>'160','paidHours'=>'0','overtimeHours'=>'0','overtimeRate'=>'1.25','bonus'=>'0','deductions'=>'0','reference'=>'ATTENDANCE','status'=>'draft']);
    }
    private function salary(array $extra=[]): array {return array_replace(['id'=>'SALARY','kind'=>'employee','party'=>'WORKER','partyId'=>'WORKER','category'=>'salary','reference'=>'Salary-2026-01','period'=>'2026-01','currency'=>'USD','date'=>'2026-01-31','dueDate'=>'2026-02-01','total'=>50000,'status'=>'draft'],$extra);}
    private function purchase(): array {
        $this->create('suppliers',['id'=>'SUPPLIER','name'=>'Supplier','active'=>true]);
        $data=['id'=>'PURCHASE','type'=>'purchase','title'=>'Buy equipment','details'=>'Equipment specifications','sourceDepartment'=>'التصميم','operation18'=>['phase'=>'new','version'=>1,'lines'=>[['id'=>'LINE','name'=>'Equipment','qty'=>2,'unit'=>'unit','kind'=>'goods']],'quotes'=>[],'receipts'=>[]]];
        $r=$this->create('support-requests',$data);$m=$r['operation18'];
        $m['quotes']=[['id'=>'QUOTE','supplierId'=>'SUPPLIER','supplierName'=>'Supplier','date'=>'2026-01-01','currency'=>'USD','paymentTerms'=>'Cash','lines'=>[['lineId'=>'LINE','name'=>'Equipment','qty'=>2,'unit'=>'unit','kind'=>'goods','price'=>10]]]];
        $m['phase']='review';$m['submittedVersion']=1;$m['recommendation']='Meets specifications';
        $m=$this->change('support-requests','PURCHASE',['operation18'=>$m])->assertOk()->json('data.operation18');
        $m['phase']='approved';$m['approval']=['id'=>'APPROVAL','quoteId'=>'QUOTE'];
        return $this->change('support-requests','PURCHASE',['operation18'=>$m])->assertOk()->json('data');
    }
    private function inventory(): string {
        $wh=Warehouse::first()->id;$this->create('stock-items',['id'=>'ITEM','name'=>'Equipment','tracking'=>'returnable','owner'=>'company','unit'=>'unit','active'=>true]);
        return $wh;
    }
    private function logistics(): array {return $this->create('support-requests',['id'=>'LOG','type'=>'logistics','title'=>'Deliver','details'=>'Delivery details','sourceDepartment'=>'التصميم','customer'=>'Client','operation18'=>['phase'=>'new']]);}
    private function reservation(string $wh,array $extra=[]): array {return array_replace(['id'=>'RES','logisticsId'=>'LOG','itemId'=>'ITEM','warehouseId'=>$wh,'qty'=>1,'start'=>'2026-10-01T09:00:00','end'=>'2026-10-01T12:00:00','status'=>'reserved'],$extra);}

    public function test_b01_explicit_read_denials_and_details_apply_to_admin_and_bootstrap(): void {
        $u=$this->actor([],['data'=>['moduleOverrides'=>['sales'=>['list'=>false,'details'=>false]]]],'all');$this->as($u);
        $this->getJson('/api/customers')->assertOk()->assertJsonCount(0,'data');
        $this->getJson('/api/customers/CLIENT')->assertNotFound();
        $this->getJson('/api/bootstrap')->assertOk()->assertJsonCount(0,'data.state.customers');
        $this->as($this->root);$u=$this->actor(['sales'=>['view'=>true,'list'=>true,'details'=>false]]);$this->as($u);
        $this->getJson('/api/customers')->assertJsonCount(1,'data');$this->getJson('/api/customers/CLIENT')->assertNotFound();
    }
    public function test_b02_invalid_scalar_filters_are_rejected_even_with_no_rows(): void {
        foreach (['sort=lines','filter[lines]=x','filter[status][x]=y'] as $query) $this->getJson('/api/quotations?'.$query)->assertUnprocessable();
        $this->getJson('/api/quotations?sort=status&filter[status]=draft')->assertOk();
    }
    public function test_b03_status_checks_session_version_and_idle_expiry(): void {
        $this->withSession(['auth_version'=>0])->getJson('/api/auth/status')->assertOk()->assertJsonPath('data.authenticated',false);
        $this->withSession(['auth_version'=>1,'last_activity'=>time()-604800])->getJson('/api/auth/status')->assertJsonPath('data.authenticated',false);
        $this->as($this->root);$this->getJson('/api/auth/status')->assertJsonPath('data.authenticated',true);
    }
    public function test_b04_lockout_response_includes_retry_after(): void {
        $this->root->update(['locked_until'=>now()->addMinutes(3)]);
        $response=$this->postJson('/api/auth/login',['username'=>'admin','password'=>'Secure-test123!'])->assertStatus(429)->assertHeader('Retry-After');
        $this->assertGreaterThan(0,(int)$response->headers->get('Retry-After'));
    }
    public function test_b05_current_user_resolves_role_permissions_and_scope(): void {
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.effectiveTaskScope','all')->assertJsonPath('data.effectivePermissions.modules.sales.edit',true)->assertJsonPath('data.role.id','app_programmer')->assertJsonMissingPath('data.password');
    }
    public function test_b06_password_must_change(): void {
        $this->patchJson('/api/auth/password',['current_password'=>'Secure-test123!','password'=>'Secure-test123!','password_confirmation'=>'Secure-test123!'])->assertUnprocessable();
    }
    public function test_b07_partial_user_and_role_updates_preserve_omitted_fields(): void {
        $u=$this->actor(['sales'=>['list'=>true]]);$before=$u->document();
        $this->patchJson('/api/users/'.$u->id,['_version'=>1,'name'=>'Changed'])->assertOk()->assertJsonPath('data.username',$before['username'])->assertJsonPath('data.employeeId',$before['employeeId']);
        $this->patchJson('/api/roles/'.$u->role_id,['_version'=>1,'name'=>'Renamed'])->assertOk()->assertJsonPath('data.modules.sales.list',true)->assertJsonPath('data.scope','custom');
    }
    public function test_b08_partial_security_update_preserves_omitted_values(): void {
        $old=Setting::valueOf('security');$this->patchJson('/api/auth/security',['sessionMinutes'=>120])->assertOk()->assertJsonPath('data.sessionMinutes',120)->assertJsonPath('data.minLength',$old['minLength']);
    }
    public function test_b09_undo_is_actor_bound_and_replay_safe(): void {
        $u=$this->actor([],[],'all');$this->as($u);
        $tx=$this->batch([['resource'=>'customers','action'=>'create','data'=>['id'=>'UNDO','name'=>'Undo']]])->assertOk()->json('data.transactionId');
        $this->as($this->root);$this->postJson('/api/transactions/'.$tx.'/undo',[],['Idempotency-Key'=>'undo-audit-123'])->assertForbidden();
        $this->as($u);$first=$this->postJson('/api/transactions/'.$tx.'/undo',[],['Idempotency-Key'=>'undo-audit-123'])->assertOk()->json();
        $second=$this->postJson('/api/transactions/'.$tx.'/undo',[],['Idempotency-Key'=>'undo-audit-123'])->assertOk()->json();$this->assertSame($first,$second);
        $this->postJson('/api/transactions/'.$tx.'/undo',[],['Idempotency-Key'=>'undo-audit-other'])->assertConflict();
    }
    public function test_b10_transaction_payloads_are_validated_before_execution(): void {
        $this->batch([['resource'=>'settings/hr','action'=>'update','version'=>1]])->assertUnprocessable();
        $this->batch([['resource'=>'customers','action'=>'unsupported','data'=>[]]])->assertStatus(400);
    }
    public function test_b11_file_names_size_and_linked_owner_access(): void {
        Storage::fake('local');
        $this->post('/api/files',['file'=>UploadedFile::fake()->createWithContent('shell.php','plain text')],['Accept'=>'application/json'])->assertUnprocessable();
        $this->post('/api/files',['file'=>UploadedFile::fake()->create('large.txt',25601,'text/plain')],['Accept'=>'application/json'])->assertStatus(413);
        $u=$this->actor(['hr'=>['edit'=>true,'details'=>true,'list'=>true]]);$this->employee('PRIVATE');$this->as($u);
        $this->post('/api/files',['file'=>UploadedFile::fake()->createWithContent('file.txt','content'),'key'=>'PRIVATE-FILE'],['Accept'=>'application/json'])->assertCreated();
        $this->change('employees','PRIVATE',['documents'=>[['key'=>'PRIVATE-FILE','name'=>'file.txt']]])->assertOk();
        $u->update(['data'=>['moduleOverrides'=>['hr'=>['details'=>false]]]]);$this->as($u);$this->getJson('/api/files/PRIVATE-FILE')->assertNotFound();
    }
    public function test_b12_missing_file_keys_abort_the_entire_save(): void {
        $this->employee();$this->change('employees','WORKER',['documents'=>[['key'=>'MISSING','name'=>'missing.pdf']]])->assertUnprocessable();
        $this->assertArrayNotHasKey('documents',Employee::find('WORKER')->data);
    }
    public function test_b13_settings_reads_and_bootstrap_use_settings_permissions(): void {
        $u=$this->actor([]);$this->as($u);
        foreach (['system','hr','hr-policies','wisdom','finance-links'] as $key) $this->getJson('/api/settings/'.$key)->assertForbidden();
        $this->getJson('/api/bootstrap')->assertOk()->assertJsonPath('data.settings.hr-policies.value',[]);
    }
    public function test_b14_finance_and_ledger_reports_enforce_scope_and_search(): void {
        $this->employee();$this->create('financial-documents',$this->salary(['department'=>'المبيعات']));
        $u=$this->actor(['accounting'=>['reports'=>true,'list'=>true]],[],'department');$this->as($u);
        $this->getJson('/api/reports/finance')->assertOk()->assertJsonCount(0,'data.rows');$this->as($this->root);
        $this->create('journal-entries',['id'=>'J1','manual'=>true,'date'=>'2026-01-01','currency'=>'USD','memo'=>'Alpha','reference'=>'J1','lines'=>[['account'=>'3000','debit'=>100,'credit'=>0],['account'=>'5200','debit'=>0,'credit'=>100]]]);
        $this->getJson('/api/reports/trial-balance?currency=USD&q=absent')->assertOk()->assertJsonCount(0,'data.rows');
        $this->getJson('/api/reports/party-statement?currency=USD&q=Alpha')->assertOk()->assertJsonCount(2,'data.rows');
    }
    public function test_b15_financial_customer_fields_require_accounting_access(): void {
        $u=$this->actor(['sales'=>['edit'=>true]]);$this->as($u);
        $this->change('customers','CLIENT',['currency'=>'EUR','paymentDays'=>30])->assertForbidden();
        $this->change('customers','CLIENT',['phone'=>'123'])->assertOk();
    }
    public function test_b16_salesperson_team_must_exist(): void {
        $this->postJson('/api/salespeople',['name'=>'Rep','salesTeam'=>'missing'])->assertUnprocessable();
        $this->create('sales-teams',['name'=>'Team','members'=>[]]);$this->create('salespeople',['name'=>'Rep','salesTeam'=>'Team']);
    }
    public function test_b17_sales_team_leader_must_exist(): void {
        $this->postJson('/api/sales-teams',['name'=>'Team','leader'=>'missing','members'=>[]])->assertUnprocessable();
        $this->create('salespeople',['name'=>'Rep']);$this->create('sales-teams',['name'=>'Team','leader'=>'Rep','members'=>[]]);
    }
    public function test_b18_default_template_is_unique_and_active(): void {
        $this->create('quotation-templates',['id'=>'TPL1','name'=>'First','isDefault'=>true,'active'=>true]);
        $this->postJson('/api/quotation-templates',['name'=>'Second','isDefault'=>true,'active'=>true])->assertUnprocessable();
        $this->change('quotation-templates','TPL1',['active'=>false])->assertUnprocessable();
        $this->create('quotation-templates',['id'=>'TPL2','name'=>'Second','isDefault'=>false,'active'=>true]);
        $this->batch([['resource'=>'quotation-templates','action'=>'update','id'=>'TPL1','version'=>1,'data'=>['isDefault'=>false]],['resource'=>'quotation-templates','action'=>'update','id'=>'TPL2','version'=>1,'data'=>['isDefault'=>true]]])->assertOk();
    }
    public function test_b19_service_contract_lines_and_customer_are_validated(): void {
        $base=['customer'=>'Client','contractTitle'=>'Contract','startDate'=>'2026-01-01','status'=>'active','lines'=>[['qty'=>1,'price'=>10]]];
        unset($base['name']);$this->postJson('/api/service-contracts',$base)->assertUnprocessable();
        $base['lines'][0]+=['service'=>'Service','description'=>'Description'];$this->create('service-contracts',$base);
        $base['customer']='Unknown';$this->postJson('/api/service-contracts',$base)->assertUnprocessable();
    }
    public function test_b20_quotation_lines_authorship_and_linked_deletion(): void {
        $base=['id'=>'Q1','customer'=>'Client','lines'=>[['type'=>'product','product'=>'Work','qty'=>1,'price'=>5,'cost'=>-1]]];
        $this->postJson('/api/quotations',$base)->assertUnprocessable();$base['lines'][0]['cost']=1;
        $base['followupNotes']=[['id'=>'NOTE','text'=>'Follow up','kind'=>'sales','by'=>'Forged','time'=>'2000-01-01']];
        $q=$this->create('quotations',$base);$this->assertSame('Admin',$q['followupNotes'][0]['by']);
        $this->change('quotations','Q1',['optional'=>[['qty'=>1,'price'=>1]]])->assertUnprocessable();
        $this->change('quotations','Q1',['status'=>'order'])->assertOk();
        $this->deleteJson('/api/quotations/Q1',['_version'=>2])->assertOk();
    }
    public function test_b21_tasks_validate_assignment_worker_transitions_and_shared_roots(): void {
        $u=$this->actor(['design'=>['status'=>true,'list'=>true,'details'=>true]]);$this->employee('OTHER');
        $this->task(['assigneeId'=>$u->employee_id,'assignee'=>$u->name]);
        $this->change('tasks','TASK',['employeeState'=>'received'])->assertForbidden();
        $this->as($u);$this->change('tasks','TASK',['employeeState'=>'submitted'])->assertUnprocessable();
        $this->change('tasks','TASK',['employeeState'=>'received'])->assertOk();$this->as($this->root);
        $this->postJson('/api/tasks',['title'=>'Bad','customer'=>'Client','department'=>'المبيعات','assignee'=>'OTHER','assigneeId'=>'OTHER'])->assertUnprocessable();
        $this->change('employees','OTHER',['employmentStatus'=>'inactive'])->assertOk();
        $this->postJson('/api/tasks',['title'=>'Bad','customer'=>'Client','department'=>'التصميم','assignee'=>'OTHER','assigneeId'=>'OTHER'])->assertUnprocessable();
        $this->change('tasks','TASK',['sharedRootTaskId'=>'missing'])->assertUnprocessable();
    }
    public function test_b22_employee_custody_discipline_and_current_profile_are_protected(): void {
        $this->employee();$this->change('employees','WORKER',['assets'=>[['id'=>'FORGED','source'=>'warehouse18','movementId'=>'M']]])->assertUnprocessable();
        $this->change('employees','WORKER',['disciplinaryRecords'=>[['id'=>'D','level'=>'warning1','reason'=>'']]])->assertUnprocessable();
        $u=$this->actor(['hr'=>['edit'=>true]]);$this->as($u);$this->change('employees',$u->employee_id,['active'=>false])->assertUnprocessable();
    }
    public function test_b23_hr_type_validation_and_hourly_leave_effect_once(): void {
        $this->employee();
        foreach (['advance','transfer','promotion','recruitment','salary_certificate','document_update','termination'] as $type) $this->postJson('/api/hr-requests',['employeeId'=>'WORKER','type'=>$type,'reason'=>'Reason','data'=>[]])->assertUnprocessable();
        $r=$this->create('hr-requests',['employeeId'=>'WORKER','type'=>'hourly_leave','reason'=>'Appointment','data'=>['date'=>'2026-01-05','fromTime'=>'10:00','toTime'=>'12:00']]);
        $this->change('hr-requests',$r['id'],['stageIndex'=>1])->assertOk();$this->change('hr-requests',$r['id'],['status'=>'approved'])->assertOk();
        $this->change('hr-requests',$r['id'],['status'=>'approved'])->assertOk();$leave=Employee::find('WORKER')->data['leaveRecords'];$this->assertCount(1,$leave);$this->assertEquals(2,$leave[0]['hours']);
    }
    public function test_b24_legacy_approvals_require_source_and_forward_stages(): void {
        $this->employee();$data=['type'=>'reward','employeeId'=>'WORKER','sourceId'=>'REWARD','title'=>'Reward','payload'=>[]];
        $this->postJson('/api/hr-approvals',$data)->assertUnprocessable();
        $this->change('employees','WORKER',['rewardRecords'=>[['id'=>'REWARD','status'=>'pending']]])->assertOk();
        $r=$this->create('hr-approvals',$data);$this->change('hr-approvals',$r['id'],['currentLevel'=>-1])->assertUnprocessable();
        $this->change('hr-approvals',$r['id'],['currentLevel'=>1])->assertOk();$this->change('hr-approvals',$r['id'],['currentLevel'=>0])->assertUnprocessable();
        $this->change('hr-approvals',$r['id'],['status'=>'approved'])->assertOk();$this->assertSame('approved',Employee::find('WORKER')->data['rewardRecords'][0]['status']);
        $data['type']='personal_change';$this->postJson('/api/hr-approvals',$data)->assertUnprocessable();
    }
    public function test_b25_closing_offboarding_disables_employee_and_linked_login(): void {
        $u=$this->actor([]);$r=$this->create('offboarding',['employeeId'=>$u->employee_id,'employeeName'=>$u->name,'reason'=>'Departure','lastWorkDay'=>'2026-01-31','status'=>'open']);
        $this->change('offboarding',$r['id'],['hr'=>true,'logistics'=>true,'accounting'=>true,'system'=>true,'status'=>'closed'])->assertOk();
        $this->assertSame('resigned',Employee::find($u->employee_id)->data['employmentStatus']);$this->assertFalse($u->fresh()->active);
    }
    public function test_b26_notices_generate_archives_preserve_reads_and_soft_delete(): void {
        $u=$this->actor([]);$v=$this->actor([]);$n=$this->create('announcements',['id'=>'NOTICE','type'=>'alert','title'=>'Notice','message'=>'Read this','targetType'=>'all','active'=>true]);
        $this->assertCount(1,Employee::find($u->employee_id)->data['hrNoticeArchive']);
        $this->as($u);$n=$this->change('announcements','NOTICE',['readBy'=>[$u->employee_id]])->assertOk()->json('data');
        $this->as($v);$this->change('announcements','NOTICE',['readBy'=>[$v->employee_id]])->assertUnprocessable();
        $this->as($this->root);$this->deleteJson('/api/announcements/NOTICE',['_version'=>$n['_version']])->assertOk()->assertJsonPath('data',null);
        $this->assertDatabaseHas('announcements',['id'=>'NOTICE']);$this->getJson('/api/announcements/NOTICE')->assertNotFound();
        $this->assertNotEmpty(Employee::find($u->employee_id)->data['hrNoticeArchive'][0]['deletedAt']);
    }
    public function test_b27_contract_clauses_and_successor_versions_are_validated(): void {
        $this->employee();$this->contract();$this->change('employment-contracts','CONTRACT',['clauses'=>[['id'=>'C','title'=>'','body'=>'Text']]])->assertUnprocessable();
        $this->change('employment-contracts','CONTRACT',['clauses'=>[['id'=>'C','title'=>'Title','body'=>'Text'],['id'=>'C','title'=>'Title','body'=>'Text']]])->assertUnprocessable();
        $this->contract(['id'=>'NEXT','parentContractId'=>'CONTRACT','version'=>2]);
        $this->postJson('/api/employment-contracts',array_replace(Records::find('employment-contracts','NEXT')->data,['id'=>'DUPLICATE']))->assertUnprocessable();
    }
    public function test_b28_executive_report_original_content_is_frozen(): void {
        $r=$this->create('executive-reports',['title'=>'Report','department'=>'التصميم','summary'=>'Original']);
        $this->change('executive-reports',$r['id'],['summary'=>'Rewrite','status'=>'reviewed'])->assertUnprocessable();
        $this->change('executive-reports',$r['id'],['status'=>'reviewed'])->assertOk()->assertJsonPath('data.summary','Original');
    }
    public function test_b29_meeting_and_decision_time_formats_are_validated(): void {
        foreach (['admin-meetings','admin-decisions'] as $resource) {
            $this->postJson('/api/'.$resource,['title'=>'Record','body'=>'Text','date'=>'2026-01-01','time'=>'invalid'])->assertUnprocessable();
            $this->create($resource,['title'=>'Record','body'=>'Text','date'=>'2026-01-01','time'=>'09:30']);
        }
    }
    public function test_b30_presence_timestamps_are_server_owned(): void {
        $this->employee();$r=$this->create('work-presence',['id'=>'id:WORKER','mode'=>'active','startedAt'=>1,'endedAt'=>1]);
        $this->assertGreaterThan(1,$r['startedAt']);$this->assertSame(0,$r['endedAt']);
        $this->change('work-presence','id:WORKER',['startedAt'=>2,'breakAt'=>2])->assertOk()->assertJsonPath('data.startedAt',$r['startedAt'])->assertJsonPath('data.breakAt',0);
    }
    public function test_b31_field_activity_ids_and_server_times(): void {
        $tpl=$this->create('field-templates',['id'=>'FIELD','label'=>'Field','active'=>true,'activities'=>[['id'=>'VISIT','label'=>'Visit','active'=>true]],'statuses'=>[['id'=>'SITE','label'=>'On site','active'=>true]]]);
        $this->employee('WORKER','التصميم',['fieldTemplateId'=>'FIELD']);
        $base=['id'=>'WORKER','employeeId'=>'WORKER','fieldTemplateId'=>'FIELD','workState'=>'working','activities'=>[['id'=>'EVENT','kind'=>'activity','label'=>'Visit']]];
        $this->postJson('/api/field-work',$base)->assertUnprocessable();$base['activities'][0]['activityId']='VISIT';$base['startedAt']='2000-01-01';
        $r=$this->create('field-work',$base);$this->assertNotSame('2000-01-01',$r['startedAt']);
        $this->change('field-work','WORKER',['startedAt'=>'2001-01-01'])->assertOk()->assertJsonPath('data.startedAt',$r['startedAt']);
    }
    public function test_b32_wisdom_order_survives_reads_and_bootstrap(): void {
        $ids=\App\Models\Wisdom::pluck('id')->all();$value=Setting::valueOf('wisdom');$value['order']=array_reverse($ids);
        $this->setting('wisdom',$value)->assertOk();$this->getJson('/api/wisdom')->assertJsonPath('data.0.id',end($ids));$this->getJson('/api/bootstrap')->assertJsonPath('data.state.wisdom.items.0.id',end($ids));
        $value['order']=['missing'];$this->setting('wisdom',$value)->assertUnprocessable();
    }
    public function test_b33_purchase_stock_receipts_are_atomic_and_logistics_release_reservations(): void {
        $wh=$this->inventory();$r=$this->purchase();$m=$r['operation18'];$m['phase']='partial';
        $m['receipts']=[['id'=>'RECEIPT','date'=>'2026-01-02','receiver'=>'Worker','lines'=>[['lineId'=>'LINE','qty'=>1,'warehouseId'=>$wh,'itemId'=>'ITEM']]]];
        $this->change('support-requests','PURCHASE',['operation18'=>$m])->assertUnprocessable();
        $this->assertEmpty(SupportRequest::find('PURCHASE')->data['operation18']['receipts']);
        $this->batch([['resource'=>'support-requests','id'=>'PURCHASE','action'=>'update','version'=>$r['_version'],'data'=>['operation18'=>$m]],['resource'=>'stock-movements','action'=>'create','data'=>['type'=>'purchase_receipt','itemId'=>'ITEM','qty'=>1,'to'=>$wh,'date'=>'2026-01-02','purchaseId'=>'PURCHASE','receiptId'=>'RECEIPT','purchaseLineId'=>'LINE']]])->assertOk();
        $this->employee();$this->logistics();$this->create('reservations',$this->reservation($wh));
        $this->change('support-requests','LOG',['operation18'=>['phase'=>'completed','assignedId'=>'WORKER','start'=>'2026-10-01T09:00','end'=>'2026-10-01T12:00']])->assertUnprocessable();
        $this->change('support-requests','LOG',['operation18'=>['phase'=>'cancelled']])->assertOk();$this->assertSame('released',Reservation::find('RES')->data['status']);
    }
    public function test_b34_warehouse_managers_and_outstanding_custody(): void {
        $this->employee('WORKER','التصميم',['active'=>false]);$this->postJson('/api/warehouses',['name'=>'Invalid','kind'=>'general','managerId'=>'WORKER'])->assertUnprocessable();
        $this->change('employees','WORKER',['active'=>true])->assertOk();$wh=$this->inventory();
        $this->create('stock-movements',['type'=>'receipt','itemId'=>'ITEM','qty'=>1,'to'=>$wh,'date'=>'2026-01-01']);
        $this->create('stock-movements',['type'=>'issue','itemId'=>'ITEM','qty'=>1,'from'=>$wh,'employeeId'=>'WORKER','date'=>'2026-01-02']);
        $this->change('warehouses',$wh,['active'=>false])->assertUnprocessable();
    }
    public function test_b35_customer_owned_stock_requires_existing_customer(): void {
        $this->postJson('/api/stock-items',['name'=>'Owned','tracking'=>'returnable','owner'=>'customer','customer'=>'Unknown'])->assertUnprocessable();
        $this->create('stock-items',['name'=>'Owned','tracking'=>'returnable','owner'=>'customer','customer'=>'Client']);
    }
    public function test_b36_transfer_requires_both_warehouse_permissions(): void {
        $u=$this->actor([]);$wh=$this->inventory();$this->change('warehouses',$wh,['managerId'=>$u->employee_id])->assertOk();
        $other=$this->create('warehouses',['name'=>'Other','kind'=>'general','active'=>true]);$this->create('stock-movements',['type'=>'receipt','itemId'=>'ITEM','qty'=>1,'to'=>$wh,'date'=>'2026-01-01']);
        $this->as($u);$this->postJson('/api/stock-movements',['type'=>'transfer','itemId'=>'ITEM','qty'=>1,'from'=>$wh,'to'=>$other['id'],'date'=>'2026-01-02'])->assertForbidden();
    }
    public function test_b37_reservations_require_active_resources_and_real_fulfillment(): void {
        $wh=$this->inventory();$this->logistics();$this->create('stock-movements',['type'=>'receipt','itemId'=>'ITEM','qty'=>2,'to'=>$wh,'date'=>'2026-01-01']);
        $this->change('stock-items','ITEM',['active'=>false])->assertOk();$this->postJson('/api/reservations',$this->reservation($wh))->assertUnprocessable();
        $this->change('stock-items','ITEM',['active'=>true])->assertOk();$this->postJson('/api/reservations',$this->reservation($wh,['status'=>'issued']))->assertUnprocessable();
        $this->create('reservations',$this->reservation($wh));
    }
    public function test_b38_legacy_invoice_recalculates_and_enforces_source_uniqueness(): void {
        $this->create('quotations',['id'=>'Q','number'=>'Q-1','customer'=>'Client','status'=>'order','lines'=>[['product'=>'Work','qty'=>2,'price'=>10.25]]]);
        $i=$this->create('invoices',['id'=>'I','customer'=>'Client','source'=>'Q-1','total'=>1,'currency'=>'USD']);$this->assertSame(2050,$i['finance21']['total']);
        $this->postJson('/api/invoices',['customer'=>'Client','source'=>'Q-1','total'=>1,'currency'=>'USD'])->assertUnprocessable();
        $this->postJson('/api/invoices',['customer'=>'Client','total'=>9999])->assertUnprocessable();
    }
    public function test_b39_financial_documents_disallow_customer_kind(): void {
        $this->postJson('/api/financial-documents',['kind'=>'customer','party'=>'Client','currency'=>'USD','date'=>'2026-01-01','dueDate'=>'2026-01-02','reference'=>'CUST','status'=>'draft','lines'=>[['name'=>'Work','qty'=>1,'price'=>1]]])->assertUnprocessable();
    }
    public function test_b40_manual_journal_defaults_zero_lines_and_reversal_currency(): void {
        $e=['id'=>'MANUAL','manual'=>true,'currency'=>'USD','date'=>'2026-01-01','memo'=>'Adjustment','reference'=>'J1','lines'=>[['account'=>'3000','debit'=>100,'credit'=>0],['account'=>'5200','debit'=>0,'credit'=>100]]];
        $bad=$e;$bad['lines'][]=['account'=>'3000','debit'=>0,'credit'=>0];$this->postJson('/api/journal-entries',$bad)->assertUnprocessable();
        $r=$this->create('journal-entries',$e);$this->assertSame('MANUAL:MANUAL',$r['key']);$this->assertSame([],$r['source']);
        $e['id']='REVERSE';$e['reverses']='MANUAL';$e['currency']='EUR';$this->postJson('/api/journal-entries',$e)->assertUnprocessable();
        $this->change('journal-entries','MANUAL',['memo'=>'Changed'])->assertUnprocessable();
    }
    public function test_b41_hours_cannot_be_superseded_while_used_by_live_salary(): void {
        $this->employee();$this->hours();$this->change('payroll-hours','HOURS',['status'=>'approved'])->assertOk();$this->create('financial-documents',$this->salary());
        $this->change('payroll-hours','HOURS',['status'=>'superseded'])->assertUnprocessable();
    }
    public function test_b42_payroll_requires_matching_fingerprint_and_snapshot(): void {
        $this->employee();$this->hours();$h=$this->change('payroll-hours','HOURS',['status'=>'approved'])->assertOk()->json('data');
        $snapshot=$h;$snapshot['fingerprint']='tampered';
        $doc=$this->salary(['payroll23'=>['runId'=>'RUN','hoursId'=>'HOURS','snapshot'=>$snapshot,'calculation'=>$h['calculation']]]);
        $run=['id'=>'RUN','period'=>'2026-01','currency'=>'USD','date'=>'2026-01-31','dueDate'=>'2026-02-01','status'=>'draft','lines'=>[['employeeId'=>'WORKER','hoursId'=>'HOURS','docId'=>'SALARY','calculation'=>$h['calculation']]]];
        $ops=[['resource'=>'financial-documents','action'=>'create','data'=>$doc],['resource'=>'payroll-runs','action'=>'create','data'=>$run]];
        $this->batch($ops)->assertUnprocessable();$this->assertDatabaseMissing('financial_documents',['id'=>'SALARY']);
        $ops[0]['data']['payroll23']['snapshot']=$h;$this->batch($ops)->assertOk();
    }
    public function test_b43_print_layout_access_is_limited_to_family_and_valid_sizes(): void {
        $u=$this->actor(['hr'=>['create'=>true,'edit'=>true,'delete'=>true,'list'=>true,'details'=>true]]);$this->as($u);
        $this->postJson('/api/print-layouts',$this->layout())->assertForbidden();
        $this->create('print-layouts',$this->layout(['id'=>'HRLAYOUT','family'=>'payslip']));
        $this->setting('print-defaults',['invoice'=>'HRLAYOUT'])->assertForbidden();
        $this->as($this->root);$this->postJson('/api/print-layouts',$this->layout(['blocks'=>[['align'=>'right','size'=>5]]]))->assertUnprocessable();
    }
    public function test_b44_hr_policy_limits_apply_to_direct_and_batch_updates(): void {
        foreach ([['workdayHours'=>25],['monthlyReviewDay'=>0],['probationDefaultMonths'=>-1]] as $value) $this->setting('hr-policies',$value)->assertUnprocessable();
        $this->batch([['resource'=>'settings/hr-policies','action'=>'update','version'=>1,'data'=>['workdayHours'=>0]]])->assertUnprocessable();
        $this->setting('hr-policies',['workdayHours'=>8,'monthlyReviewDay'=>25,'probationDefaultMonths'=>3])->assertOk();
    }
    public function test_b45_settings_require_active_employees_and_known_departments(): void {
        $this->employee('WORKER','التصميم',['active'=>false]);$this->setting('department-managers',['التصميم'=>'WORKER'])->assertUnprocessable();$this->setting('purchasing',['WORKER'])->assertUnprocessable();
        $this->change('employees','WORKER',['active'=>true])->assertOk();$this->setting('department-managers',['invalid'=>'WORKER'])->assertUnprocessable();$this->setting('purchasing',['WORKER'])->assertOk();
    }
    public function test_b46_shared_clauses_require_content_and_unique_stable_ids(): void {
        $this->setting('employment-contract-templates',['common'=>[['id'=>'C','title'=>'','body'=>'Text']]])->assertUnprocessable();
        $this->setting('employment-contract-templates',['common'=>[['id'=>'C','title'=>'Title','body'=>'Text']],'types'=>[]])->assertOk()->assertJsonPath('data.value.version',2);
    }
    public function test_b47_review_map_validates_report_and_overwrites_actor_time(): void {
        $this->setting('executive-report-reviews',['missing'=>['by'=>'Forged','at'=>'2000-01-01']])->assertUnprocessable();
        $r=$this->create('executive-reports',['title'=>'Report','department'=>'التصميم','summary'=>'Content']);
        $v=$this->setting('executive-report-reviews',[$r['id']=>['by'=>'Forged','at'=>'2000-01-01']])->assertOk()->json('data.value');$this->assertSame('Admin',$v[$r['id']]['by']);$this->assertNotSame('2000-01-01',$v[$r['id']]['at']);
    }
    public function test_b48_finance_party_links_require_admin_and_unposted_documents(): void {
        $this->employee();$this->create('financial-documents',$this->salary());
        $u=$this->actor(['accounting'=>['edit'=>true]]);$this->as($u);$this->setting('finance-links',['SALARY'=>'employee:WORKER'])->assertForbidden();
        $this->as($this->root);$this->setting('finance-links',['SALARY'=>'employee:WORKER'])->assertOk();
        $this->change('financial-documents','SALARY',['status'=>'review'])->assertOk();$this->change('financial-documents','SALARY',['status'=>'posted','decisionReason'=>'Approved'])->assertOk();
        $this->employee('OTHER');$this->setting('finance-links',['SALARY'=>'employee:OTHER'])->assertConflict();
    }    public function test_b01_b11_explicit_denial_hides_linked_files_and_report_rows(): void {
        Storage::fake('local');$this->employee();
        $this->create('financial-documents',$this->salary());
        $u=$this->actor([],['data'=>['moduleOverrides'=>['accounting'=>['list'=>false,'details'=>false]]]],'all');
        $this->post('/api/files',['file'=>UploadedFile::fake()->createWithContent('document.txt','data'),'key'=>'DOC'],['Accept'=>'application/json'])->assertCreated();
        $this->change('financial-documents','SALARY',['files'=>[['key'=>'DOC','name'=>'document.txt']]])->assertOk();
        $this->as($u);$this->getJson('/api/financial-documents')->assertJsonCount(0,'data');$this->getJson('/api/reports/finance')->assertJsonCount(0,'data.rows');$this->getJson('/api/files/DOC')->assertNotFound();
    }
    public function test_b10_malformed_operations_never_produce_server_error(): void {
        $this->postJson('/api/transactions',['operations'=>'invalid'],['Idempotency-Key'=>'malformed-123'])->assertUnprocessable();
        $this->batch([['resource'=>'customers','action'=>'create']])->assertUnprocessable();
    }
    public function test_b20_linked_order_cannot_be_deleted_and_nonproduct_lines_are_validated(): void {
        $q=$this->create('quotations',['id'=>'ORDER','number'=>'ORDER-1','customer'=>'Client','status'=>'order','lines'=>[['product'=>'Work','qty'=>1,'price'=>1]]]);
        $this->create('invoices',['customer'=>'Client','source'=>'ORDER-1','currency'=>'USD']);
        $this->deleteJson('/api/quotations/ORDER',['_version'=>$q['_version']])->assertConflict();
        $this->postJson('/api/quotations',['customer'=>'Client','lines'=>[['type'=>'note','description'=>'']]])->assertUnprocessable();
    }
    public function test_b21_only_accounting_bills_packages_and_appointed_manager_can_cross_departments(): void {
        $u=$this->actor(['design'=>['edit'=>true,'approve'=>true,'list'=>true,'details'=>true]]);
        $parent=$this->task();$this->task(['id'=>'CHILD','parentTaskId'=>'TASK','status'=>'completed']);
        $this->as($u);$this->change('tasks','TASK',['status'=>'awaiting_invoice'])->assertForbidden();
        $this->as($this->root);$this->change('tasks','TASK',['status'=>'awaiting_invoice'])->assertOk();
        $this->employee('MANAGER','المبيعات');$this->setting('department-managers',['التصميم'=>'MANAGER'])->assertOk();
        $this->task(['id'=>'MANAGER-TASK','assigneeId'=>'MANAGER','assignee'=>'MANAGER']);
        $this->task(['id'=>'ID-ONLY','assigneeId'=>$u->employee_id]);
        $this->getJson('/api/tasks/ID-ONLY')->assertJsonPath('data.assigneeId',$u->employee_id);
    }
    public function test_b21_shared_children_cannot_escape_their_root(): void {
        $this->task(['id'=>'ROOT','department'=>'المهمات','sharedDistributionRoot'=>true]);
        $this->task(['id'=>'CHILD','parentTaskId'=>'ROOT','sharedDistributionChild'=>true,'sharedRootTaskId'=>'ROOT','sharedNodeType'=>'department']);
        $this->change('tasks','CHILD',['sharedRootTaskId'=>null])->assertUnprocessable();
        $this->change('tasks','ROOT',['sharedDistributionRoot'=>false])->assertUnprocessable();
        $this->change('tasks','ROOT',['status'=>'completed'])->assertUnprocessable();
    }
    public function test_task_graph_uses_final_parent_links_and_rolls_back_an_invalid_move(): void
    {
        $original = $this->task(['id'=>'ORIGINAL']);
        $destination = $this->task(['id'=>'DESTINATION']);
        $child = $this->task(['id'=>'CHILD','parentTaskId'=>'ORIGINAL']);

        $this->batch([
            ['resource'=>'tasks','action'=>'update','id'=>'ORIGINAL','version'=>$original['_version'],'data'=>['status'=>'completed']],
            ['resource'=>'tasks','action'=>'update','id'=>'CHILD','version'=>$child['_version'],'data'=>['parentTaskId'=>'DESTINATION']],
        ])->assertOk();

        $this->change('tasks','CHILD',['parentTaskId'=>'ORIGINAL'])->assertUnprocessable()
            ->assertJsonPath('message','أكمل المهام الفرعية أولاً.');
        $saved = Task::findOrFail('CHILD');
        $this->assertSame('DESTINATION',$saved->parent_id);
        $this->assertSame($child['_version']+1,$saved->version);
        $this->assertSame($destination['_version'],Task::findOrFail('DESTINATION')->version);
        $this->patchJson('/api/tasks/CHILD',['_version'=>$child['_version'],'title'=>'Stale'])->assertConflict();
        $this->deleteJson('/api/tasks/CHILD',['_version'=>$saved->version])->assertForbidden();
    }
    public function test_task_graph_checks_dependants_when_shared_root_or_parent_changes(): void
    {
        $this->create('customers',['id'=>'OTHER','name'=>'Other']);
        $this->task(['id'=>'700','sharedDistributionRoot'=>true]);
        $this->task(['id'=>'BRANCH','parentTaskId'=>'700','sharedRootTaskId'=>700]);
        $this->task(['id'=>'LEAF','parentTaskId'=>'BRANCH','sharedRootTaskId'=>'700','sharedDistributionChild'=>true]);
        // Preserve the parent link of an older record that has only the document field.
        Task::whereKey('LEAF')->update(['parent_id'=>null]);

        $this->change('tasks','700',['customer'=>'Other','customerId'=>'OTHER'])->assertUnprocessable()
            ->assertJsonPath('message','عميل المهمة المشتركة مختلف.');
        $this->change('tasks','BRANCH',['sharedRootTaskId'=>null])->assertUnprocessable()
            ->assertJsonPath('message','الأب لا يتبع أصل المهمة المشتركة.');
        $this->change('tasks','700',['sharedDistributionRoot'=>false])->assertUnprocessable()
            ->assertJsonPath('message','أصل المهمة المشتركة غير صالح.');

        $updates = [];
        foreach (['700','BRANCH','LEAF'] as $id) {
            $updates[] = ['resource'=>'tasks','action'=>'update','id'=>$id,'version'=>Task::findOrFail($id)->version,
                'data'=>['customer'=>'Other','customerId'=>'OTHER']];
        }
        $this->batch($updates)->assertOk();
        $this->assertSame('Other',Task::findOrFail('LEAF')->data['customer']);
    }
    public function test_b22_linked_hr_sources_cannot_be_deleted_or_self_approved(): void {
        $this->employee();$this->change('employees','WORKER',['rewardRecords'=>[['id'=>'REWARD','status'=>'pending']]])->assertOk();
        $a=$this->create('hr-approvals',['type'=>'reward','employeeId'=>'WORKER','sourceId'=>'REWARD','title'=>'Reward']);
        $record=['id'=>'REWARD','status'=>'pending','approvalId'=>$a['id']];$this->change('employees','WORKER',['rewardRecords'=>[$record]])->assertOk();
        $this->change('employees','WORKER',['rewardRecords'=>[]])->assertUnprocessable();
        $record['status']='approved';$this->change('employees','WORKER',['rewardRecords'=>[$record]])->assertUnprocessable();
    }
    public function test_b23_document_request_requires_owned_real_upload_and_currency(): void {
        Storage::fake('local');$u=$this->actor(['hr'=>['create'=>true,'list'=>true,'details'=>true]]);$this->employee();
        $this->post('/api/files',['file'=>UploadedFile::fake()->createWithContent('document.txt','data'),'key'=>'ROOT-FILE'],['Accept'=>'application/json'])->assertCreated();
        $this->as($u);$this->postJson('/api/hr-requests',['employeeId'=>'WORKER','type'=>'document_update','reason'=>'Update','data'=>['docType'=>'ID','files'=>[['key'=>'ROOT-FILE','name'=>'document.txt']]]])->assertForbidden();
        $this->post('/api/files',['file'=>UploadedFile::fake()->createWithContent('document.txt','data'),'key'=>'OWN-FILE'],['Accept'=>'application/json'])->assertCreated();
        $this->create('hr-requests',['employeeId'=>$u->employee_id,'type'=>'document_update','reason'=>'Update','data'=>['docType'=>'ID','files'=>[['key'=>'OWN-FILE','name'=>'document.txt']]]]);
        $this->postJson('/api/hr-requests',['employeeId'=>$u->employee_id,'type'=>'advance','reason'=>'Advance','data'=>['amount'=>100]])->assertUnprocessable();
    }
    public function test_b27_malformed_and_nonexistent_parent_contracts_fail_validation(): void {
        $this->employee();$c=$this->contract();
        $this->change('employment-contracts','CONTRACT',['clauses'=>'invalid'])->assertUnprocessable();
        unset($c['_version']);$c['id']='NEW';$c['parentContractId']='missing';$c['version']=2;
        $this->postJson('/api/employment-contracts',$c)->assertUnprocessable();
    }
    public function test_b33_inactive_supplier_and_offer_unit_mismatch_are_rejected(): void {
        $r=$this->purchase();$m=$r['operation18'];
        $this->create('support-requests',['id'=>'P2','type'=>'purchase','title'=>'Purchase','details'=>'Specs','operation18'=>['phase'=>'new','lines'=>$m['lines']]]);
        $new=['phase'=>'new','lines'=>$m['lines'],'quotes'=>$m['quotes']];$new['quotes'][0]['lines'][0]['unit']='wrong';
        $this->change('support-requests','P2',['operation18'=>$new])->assertUnprocessable();
        $new['quotes']=$m['quotes'];$this->change('suppliers','SUPPLIER',['active'=>false])->assertOk();$this->change('support-requests','P2',['operation18'=>$new])->assertUnprocessable();
    }
    public function test_b33_b36_receipt_item_mismatch_and_service_stock_roll_back(): void {
        $wh=$this->inventory();$r=$this->purchase();$m=$r['operation18'];
        $this->create('stock-items',['id'=>'OTHER','name'=>'Other','tracking'=>'returnable','owner'=>'company','unit'=>'unit']);
        $m['phase']='partial';$m['receipts']=[['id'=>'RECEIPT','date'=>'2026-01-02','receiver'=>'Worker','lines'=>[['lineId'=>'LINE','qty'=>1,'warehouseId'=>$wh,'itemId'=>'ITEM']]]];
        $ops=[['resource'=>'support-requests','id'=>'PURCHASE','action'=>'update','version'=>$r['_version'],'data'=>['operation18'=>$m]],['resource'=>'stock-movements','action'=>'create','data'=>['type'=>'purchase_receipt','itemId'=>'OTHER','qty'=>1,'to'=>$wh,'date'=>'2026-01-02','purchaseId'=>'PURCHASE','receiptId'=>'RECEIPT','purchaseLineId'=>'LINE']]];
        $this->batch($ops)->assertUnprocessable();$this->assertSame(0,StockMovement::count());
        // An approved service-only order cannot be received into a warehouse.
        $service=SupportRequest::find('PURCHASE');$d=$service->data;$d['operation18']['lines'][0]['kind']='service';$d['operation18']['quotes'][0]['lines'][0]['kind']='service';$d['operation18']['approval']['quote']['lines'][0]['kind']='service';$service->update(['data'=>$d]);
        $m=$d['operation18'];$m['receipts']=[['id'=>'SERVICE-RECEIPT','date'=>'2026-01-02','receiver'=>'Worker','lines'=>[['lineId'=>'LINE','qty'=>1,'warehouseId'=>$wh,'itemId'=>'ITEM']]]];
        $this->change('support-requests','PURCHASE',['operation18'=>$m])->assertUnprocessable();
    }
    public function test_b37_issued_reservation_has_one_matching_authorized_movement(): void {
        $this->employee();$wh=$this->inventory();$this->logistics();
        $this->create('stock-movements',['type'=>'receipt','itemId'=>'ITEM','qty'=>2,'to'=>$wh,'date'=>'2026-01-01']);
        $this->create('reservations',$this->reservation($wh));
        $this->batch([['resource'=>'reservations','id'=>'RES','action'=>'update','version'=>1,'data'=>['status'=>'issued','movementId'=>'ISSUE']],['resource'=>'stock-movements','action'=>'create','data'=>['id'=>'ISSUE','type'=>'issue','itemId'=>'ITEM','qty'=>1,'from'=>$wh,'employeeId'=>'WORKER','logisticsId'=>'LOG','date'=>'2026-01-02']]])->assertOk();
        $this->postJson('/api/reservations',$this->reservation($wh,['id'=>'COPY','status'=>'issued','movementId'=>'ISSUE']))->assertUnprocessable();
    }
    public function test_b40_manual_reversal_preserves_currency_and_amounts(): void {
        $this->create('journal-entries',['id'=>'ORIGINAL','manual'=>true,'date'=>'2026-01-01','currency'=>'USD','reference'=>'J1','memo'=>'Original','lines'=>[['account'=>'3000','debit'=>100,'credit'=>0],['account'=>'5200','debit'=>0,'credit'=>100]]]);
        $j=$this->create('journal-entries',['id'=>'REVERSAL','manual'=>true,'date'=>'2026-01-02','currency'=>'USD','reference'=>'J2','memo'=>'Correction reason','reverses'=>'ORIGINAL','lines'=>[['account'=>'3000','debit'=>999,'credit'=>0]]]);
        $this->assertSame(100,$j['lines'][0]['credit']);$this->assertSame(0,$j['lines'][0]['debit']);
    }
    public function test_b42_correct_payroll_snapshot_and_status_updates_are_atomic(): void {
        $this->employee();$this->hours();$h=$this->change('payroll-hours','HOURS',['status'=>'approved'])->assertOk()->json('data');
        $doc=$this->salary(['payroll23'=>['runId'=>'RUN','hoursId'=>'HOURS','snapshot'=>$h,'calculation'=>$h['calculation']]]);
        $run=['id'=>'RUN','period'=>'2026-01','currency'=>'USD','date'=>'2026-01-31','dueDate'=>'2026-02-01','status'=>'draft','lines'=>[['employeeId'=>'WORKER','hoursId'=>'HOURS','docId'=>'SALARY','calculation'=>$h['calculation']]]];
        $this->batch([['resource'=>'financial-documents','action'=>'create','data'=>$doc],['resource'=>'payroll-runs','action'=>'create','data'=>$run]],'payroll-create')->assertOk();
        $this->change('payroll-runs','RUN',['status'=>'review'])->assertUnprocessable();
        $this->batch([['resource'=>'financial-documents','id'=>'SALARY','action'=>'update','version'=>1,'data'=>['status'=>'review']],['resource'=>'payroll-runs','id'=>'RUN','action'=>'update','version'=>1,'data'=>['status'=>'review']]],'payroll-review')->assertOk();
        $this->assertSame('review',PayrollRun::find('RUN')->data['status']);
    }

}

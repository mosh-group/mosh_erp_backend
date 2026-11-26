<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Hash,DB};
use App\Models\{User,Employee,Customer,Task};
class ApiTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void { parent::setUp();$this->seed(); }
    private function setupRoot(): array {
        return $this->postJson('/api/auth/setup',['name'=>'Test Admin','username'=>'admin','password'=>'Test-Password123!','password_confirmation'=>'Test-Password123!'])->assertCreated()->json('data.user');
    }
    public function test_setup_login_password_and_session_logout(): void {
        $this->getJson('/api/auth/status')->assertOk()->assertJsonPath('data.setupRequired',true);
        $user=$this->setupRoot();$this->assertTrue(Hash::check('Test-Password123!',User::first()->password));
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.username','admin')->assertJsonMissingPath('data.password');
        $this->postJson('/api/auth/setup',['name'=>'Other','username'=>'other','password'=>'Other-password1','password_confirmation'=>'Other-password1'])->assertConflict();
        $this->patchJson('/api/auth/password',['current_password'=>'incorrect','password'=>'Changed-Password123!','password_confirmation'=>'Changed-Password123!'])->assertUnprocessable();
        $this->patchJson('/api/auth/password',['current_password'=>'Test-Password123!','password'=>'Changed-Password123!','password_confirmation'=>'Changed-Password123!'])->assertOk();
        $this->postJson('/api/auth/logout')->assertOk();$this->getJson('/api/bootstrap')->assertUnauthorized();
        $this->postJson('/api/auth/login',['username'=>'admin','password'=>'Changed-Password123!'])->assertOk();
        $this->getJson('/api/bootstrap')->assertOk()->assertJsonPath('data.user.username','admin');
    }

    public function test_welcome_receipt_belongs_to_the_current_account_and_survives_login(): void
    {
        $root = $this->setupRoot();
        $this->getJson('/api/auth/status')->assertOk()->assertJsonPath('data.welcomeAccountId', $root['id']);
        $this->postJson('/api/auth/welcome', ['accountId' => 'ANOTHER-ACCOUNT'])->assertConflict();
        $receipt = $this->postJson('/api/auth/welcome', ['accountId' => $root['id']])
            ->assertOk()->assertJsonPath('data.accountId', $root['id'])->json('data.welcomeAcknowledgedAt');
        $this->assertNotEmpty($receipt);
        $this->postJson('/api/auth/welcome', ['accountId' => $root['id']])
            ->assertOk()->assertJsonPath('data.welcomeAcknowledgedAt', $receipt);
        $this->assertSame('dashboard', User::findOrFail($root['id'])->data['landing']);

        $this->postJson('/api/employees', [
            'id' => 'WELCOME-EMP', 'name' => 'Welcome Employee', 'department' => 'التصميم',
            'jobTitle' => 'Designer', 'hireDate' => '2026-01-01', 'attendanceMode' => 'daily',
        ])->assertCreated();
        $employeeId = $this->postJson('/api/users', [
            'name' => 'Welcome Employee', 'username' => 'welcome_emp', 'password' => 'Synthetic-Password123!',
            'roleId' => 'role_employee', 'employeeId' => 'WELCOME-EMP', 'active' => true, 'mustChange' => true,
        ])->assertCreated()->json('data.id');
        $this->postJson('/api/auth/logout')->assertOk();
        $this->postJson('/api/auth/welcome', ['accountId' => $employeeId])->assertUnauthorized();
        $this->getJson('/api/auth/status')->assertOk()->assertJsonPath('data.welcomeAccountId', null);
        $this->postJson('/api/auth/login', ['username' => 'welcome_emp', 'password' => 'Synthetic-Password123!'])
            ->assertOk()->assertJsonPath('data.user.mustChange', true)->assertJsonMissingPath('data.user.welcomeAcknowledgedAt');
        $this->getJson('/api/auth/status')->assertOk()->assertJsonPath('data.welcomeAccountId', $employeeId);
        $this->postJson('/api/auth/welcome', ['accountId' => $employeeId])->assertOk();
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.mustChange', true);
        $this->getJson('/api/communication/directory')->assertForbidden();

        $this->postJson('/api/auth/logout')->assertOk();
        $this->postJson('/api/auth/login', ['username' => 'admin', 'password' => 'Test-Password123!'])
            ->assertOk()->assertJsonPath('data.user.welcomeAcknowledgedAt', $receipt);
        $this->getJson('/api/auth/status')->assertOk()->assertJsonPath('data.welcomeAccountId', null);
    }
    public function test_employee_photo_is_saved_and_readable_by_the_employee(): void
    {
        $this->setupRoot();
        \Illuminate\Support\Facades\Storage::fake('local');
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jLM0AAAAASUVORK5CYII=');
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('employee.png', $bytes);
        $key = $this->post('/api/files', ['file'=>$file, 'kind'=>'staff_photo'], ['Accept'=>'application/json'])
            ->assertCreated()->json('data.key');
        $profile = $this->postJson('/api/employees', [
            'id'=>'PHOTO-EMP','name'=>'Photo Fixture','department'=>'التصميم','jobTitle'=>'Designer',
            'hireDate'=>'2026-01-01','attendanceMode'=>'daily',
        ])->assertCreated()->json('data');
        $profile = $this->patchJson('/api/employees/PHOTO-EMP', [
            '_version'=>$profile['_version'], 'photoKey'=>$key, 'photoName'=>'employee.png',
            'photoUpdatedAt'=>now()->toIso8601String(),
        ])->assertOk()->assertJsonPath('data.photoKey',$key)->json('data');
        $this->assertDatabaseHas('file_links', ['resource'=>'employees', 'record_id'=>'PHOTO-EMP']);

        $invalid = \Illuminate\Http\UploadedFile::fake()->createWithContent('test.txt', 'Fixture text');
        $textKey = $this->post('/api/files', ['file'=>$invalid], ['Accept'=>'application/json'])->assertCreated()->json('data.key');
        $this->patchJson('/api/employees/PHOTO-EMP', ['_version'=>$profile['_version'], 'photoKey'=>$textKey])->assertUnprocessable();
        $this->getJson('/api/employees/PHOTO-EMP')->assertOk()->assertJsonPath('data.photoKey',$key);

        $this->postJson('/api/users', [
            'name'=>'Photo Fixture','username'=>'photo_fixture','password'=>'Synthetic-PhotoPass123!',
            'roleId'=>'role_employee','employeeId'=>'PHOTO-EMP','active'=>true,'mustChange'=>false,
        ])->assertCreated();
        $this->postJson('/api/auth/logout')->assertOk();
        $this->postJson('/api/auth/login', ['username'=>'photo_fixture','password'=>'Synthetic-PhotoPass123!'])->assertOk();
        $this->getJson('/api/employees/PHOTO-EMP')->assertOk()->assertJsonPath('data.photoKey',$key);
        $this->get('/api/files/'.$key.'/content')->assertOk()->assertHeader('Content-Type','image/png');
    }

    public function test_employee_relative_is_saved_and_legacy_notes_are_preserved(): void
    {
        $this->setupRoot();
        $base = ['department'=>'التصميم','jobTitle'=>'Designer','hireDate'=>'2026-01-01','attendanceMode'=>'daily'];
        $this->postJson('/api/employees', $base + ['id'=>'RELATIVE','name'=>'Relative Fixture'])->assertCreated();
        $profile = $this->postJson('/api/employees', $base + [
            'id'=>'EMP-LINK','name'=>'Linked Fixture','hasRelatives'=>true,
            'relativeEmployeeId'=>'RELATIVE','relativeRelationship'=>'أخت',
        ])->assertCreated()->assertJsonPath('data.relativeEmployeeId','RELATIVE')
            ->assertJsonPath('data.relativeRelationship','أخت')->json('data');

        $path = '/api/employees/EMP-LINK';
        $version = ['_version'=>$profile['_version']];
        $this->getJson($path)->assertOk()->assertJsonPath('data.relativeEmployeeId','RELATIVE');
        $this->patchJson($path, $version + ['relativeEmployeeId'=>'EMP-LINK'])->assertUnprocessable();
        $this->patchJson($path, $version + ['relativeEmployeeId'=>'MISSING'])->assertUnprocessable();
        $this->patchJson($path, $version + ['relativeRelationship'=>''])->assertUnprocessable();
        $this->patchJson($path, $version + ['hasRelatives'=>false])->assertOk()
            ->assertJsonPath('data.relativeEmployeeId','')->assertJsonPath('data.relativeRelationship','');

        $legacy = $this->postJson('/api/employees', $base + [
            'id'=>'LEGACY-LINK','name'=>'Legacy Fixture','hasRelatives'=>true,'relativesDetails'=>'بيان محفوظ',
        ])->assertCreated()->json('data');
        $this->patchJson('/api/employees/LEGACY-LINK', [
            '_version'=>$legacy['_version'],'relativeEmployeeId'=>'','relativeRelationship'=>'','notes'=>'تحديث الملف',
        ])->assertOk()->assertJsonPath('data.relativesDetails','بيان محفوظ');
    }

    public function test_platform_backup_manager_covers_only_the_department_until_the_manager_returns(): void
    {
        $root = $this->setupRoot();
        $department = 'MOSH MEDIA';
        $day = now(config('app.timezone'))->setTime(10, 0);
        $this->travelTo($day);
        foreach (['PRIMARY'=>'التصميم','BACKUP'=>'التصوير','WORKER'=>$department] as $id=>$assigned) {
            Employee::create([
                'id'=>$id, 'department'=>$assigned, 'version'=>1,
                'data'=>['id'=>$id,'name'=>$id,'department'=>$assigned,'active'=>true,'employmentStatus'=>'active'],
            ]);
        }
        foreach (['PRIMARY','BACKUP'] as $id) {
            User::create([
                'id'=>'USER-'.$id,'name'=>$id,'username'=>strtolower($id),'password'=>'Synthetic-password123!',
                'role_id'=>'role_employee','department'=>$id==='PRIMARY'?'التصميم':'التصوير',
                'employee_id'=>$id,'active'=>true,'must_change'=>false,'auth_version'=>1,'version'=>1,'data'=>[],
            ]);
        }
        $this->putJson('/api/hr/department-managers', [
            'department'=>$department,'managerId'=>'PRIMARY','expectedManagerId'=>'','requestId'=>'primary-platform-qa',
        ])->assertOk()->assertJsonPath('data.coverage.effective.'.$department,'PRIMARY');
        $backupRoute = '/api/hr/department-managers/backup';
        $selection=['department'=>$department,'managerId'=>'BACKUP','expectedManagerId'=>'','requestId'=>'backup-platform-qa'];
        $this->putJson($backupRoute, array_replace($selection,['managerId'=>'PRIMARY']))->assertUnprocessable();
        $this->putJson($backupRoute,$selection)->assertOk()->assertJsonPath('data.backups.'.$department,'BACKUP')
            ->assertJsonPath('data.effective.'.$department,'PRIMARY');
        $this->putJson($backupRoute,array_replace($selection,['requestId'=>'stale-backup-platform']))->assertConflict();

        foreach (['PENDING'=>$department,'UNRELATED'=>'التصوير'] as $id=>$assigned) {
            Task::create(['id'=>$id,'employee_id'=>'WORKER','department'=>$assigned,'status'=>'in_progress','version'=>1,
                'data'=>['id'=>$id,'assigneeId'=>'WORKER','department'=>$assigned,'status'=>'in_progress','employeeState'=>'submitted','title'=>$id]]);
        }
        $backup=User::findOrFail('USER-BACKUP');
        $this->actingAs($backup)->withSession(['auth_version'=>1,'last_activity'=>time()]);
        $this->getJson('/api/tasks')->assertOk()->assertJsonMissing(['id'=>'PENDING']);
        $this->putJson($backupRoute,array_replace($selection,['requestId'=>'forbidden-backup-change']))->assertForbidden();
        $primary=Employee::findOrFail('PRIMARY');
        User::whereKey('USER-PRIMARY')->update(['active'=>false]);
        $this->assertFalse($backup->manages($department));
        $this->getJson('/api/tasks')->assertOk()->assertJsonMissing(['id'=>'PENDING']);

        $leave = ['type'=>'سنوية','startDate'=>$day->toDateString(),'endDate'=>$day->toDateString(),'status'=>'pending'];
        $primary->update(['data'=>array_replace($primary->data, ['leaveRecords'=>[$leave]])]);
        $this->assertFalse($backup->manages($department));
        $leave['status'] = 'approved';
        $primary->update(['data'=>array_replace($primary->data, ['leaveRecords'=>[$leave]])]);

        \App\Support\R19Store::put(\App\Services\R34\Hierarchy::KEY, ['enabled'=>true,'generalManagerId'=>'PRIMARY'], 0);
        $approval = ['id'=>'TEST-LEAVE','type'=>'sick_leave','employeeId'=>'WORKER','department'=>$department,
            'status'=>'pending','stageIndex'=>0,'route'=>['المدير المباشر','الموارد البشرية']];
        \App\Services\R34\Hierarchy::prepareHr($backup, null, $approval);
        \App\Models\HrRequest::create(['id'=>$approval['id'],'employee_id'=>'WORKER','department'=>$department,
            'status'=>'pending','version'=>1,'data'=>$approval]);
        $this->assertSame('USER-PRIMARY', $approval['r34Approval']['byStage'][0]['userId']);
        $this->getJson('/api/r34/approvals')->assertOk()->assertJsonPath('data.hr.0.id','TEST-LEAVE')
            ->assertJsonPath('data.hr.0.canDecide',true);
        $this->assertSame('BACKUP', \App\Support\R19HrAccess::directManagerId(Employee::findOrFail('WORKER')));

        $this->assertTrue($backup->manages($department));
        $this->assertTrue($backup->allows('platform','approve'));
        $this->assertFalse($backup->globalPermission('manageUsers'));
        $this->assertFalse(User::findOrFail('USER-PRIMARY')->manages($department));
        $this->getJson('/api/tasks')->assertOk()->assertJsonFragment(['id'=>'PENDING'])->assertJsonMissing(['id'=>'UNRELATED']);
        $this->getJson('/api/r19/notifications')->assertOk()->assertJsonFragment(['taskId'=>'PENDING']);

        $this->travelTo($day->copy()->addDay());
        $this->assertFalse($backup->manages($department));
        $this->getJson('/api/r19/notifications')->assertOk()->assertJsonMissing(['taskId'=>'PENDING']);
        $this->getJson('/api/tasks')->assertOk()->assertJsonMissing(['id'=>'PENDING']);
        $this->assertSame('PRIMARY', \App\Models\Setting::valueOf('department-managers')[$department]);

        $this->getJson('/api/r34/approvals')->assertOk()->assertJsonMissing(['id'=>'TEST-LEAVE']);
        $this->assertFalse($backup->allows('platform','approve'));
        User::whereKey('USER-PRIMARY')->update(['active'=>true]);
        $this->assertTrue(\App\Services\R34\Hierarchy::isHrApprover(User::findOrFail('USER-PRIMARY'), $approval));

        $hourlyLeave = ['type'=>'ساعية','startDate'=>now()->toDateString(),'endDate'=>now()->toDateString(),
            'fromTime'=>'10:15','toTime'=>'11:15','status'=>'approved'];
        $primary->update(['data'=>array_replace($primary->data, ['leaveRecords'=>[$hourlyLeave]])]);
        $this->assertFalse($backup->manages($department));
        $this->travelTo(now()->setTime(10, 15));
        $this->assertTrue($backup->manages($department));
        $this->travelTo(now()->setTime(11, 15));
        $this->assertFalse($backup->manages($department));
    }

    public function test_login_limit_isolates_failed_accounts_on_a_shared_network(): void {
        $this->setupRoot();
        for ($i = 0; $i < 21; $i++) {
            $this->postJson('/api/auth/login',['username'=>'admin','password'=>'Test-Password123!'])->assertOk();
        }
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/auth/login',['username'=>'another-account'])->assertUnprocessable();
        }
        $this->postJson('/api/auth/login',['username'=>'ANOTHER-ACCOUNT'])->assertTooManyRequests()->assertHeader('Retry-After');
        $this->postJson('/api/auth/login',['username'=>'admin','password'=>'Test-Password123!'])->assertOk();

        $maxAttempts=\App\Models\Setting::valueOf('security')['maxAttempts']??5;
        for ($i = 0; $i < $maxAttempts; $i++) {
            $this->postJson('/api/auth/login',['username'=>'admin','password'=>'Incorrect-Password123!'])->assertUnauthorized();
        }
        $this->postJson('/api/auth/login',['username'=>'admin','password'=>'Test-Password123!'])->assertTooManyRequests()->assertHeader('Retry-After');
    }
    public function test_final_subtask_approval_moves_package_to_follow_up_atomically(): void {
        $this->setupRoot();
        $this->postJson('/api/customers',['id'=>'CLIENT','name'=>'Package Customer'])->assertCreated();
        $base=['customer'=>'Package Customer','customerId'=>'CLIENT','status'=>'in_progress','history'=>[]];
        Task::create(['id'=>'PACKAGE','department'=>'التسويق','status'=>'in_progress','customer_id'=>'CLIENT',
            'data'=>$base+['id'=>'PACKAGE','title'=>'Package','department'=>'التسويق','employeeState'=>'new']]);
        foreach (['SERVICE1'=>'التسويق','SERVICE2'=>'التصميم'] as $id=>$department) {
            Task::create(['id'=>$id,'department'=>$department,'status'=>'in_progress','customer_id'=>'CLIENT','parent_id'=>'PACKAGE',
                'data'=>$base+['id'=>$id,'title'=>$id,'department'=>$department,'parentTaskId'=>'PACKAGE','employeeState'=>'submitted']]);
        }
        $finish=fn (string $id)=>$this->withHeader('Idempotency-Key','approve-'.$id)->postJson('/api/transactions',['operations'=>[[
            'resource'=>'tasks','id'=>$id,'action'=>'update','version'=>1,'data'=>['status'=>'completed','employeeState'=>'finished'],
        ]]]);
        $finish('SERVICE1')->assertOk()->assertJsonCount(1,'data.changes');
        $this->assertSame('التسويق',Task::findOrFail('PACKAGE')->department);
        $finish('SERVICE2')->assertOk()->assertJsonCount(2,'data.changes');
        $parent=Task::findOrFail('PACKAGE');
        $this->assertSame('المهمات',$parent->department);
        $this->assertTrue($parent->data['packageReady']);
        $this->assertSame('completed',Task::findOrFail('SERVICE2')->status);
        $finish('SERVICE2')->assertOk()->assertJsonCount(2,'data.changes');
        $this->assertSame($parent->version,Task::findOrFail('PACKAGE')->version);
    }
    public function test_crud_search_pagination_conflict_atomic_rollback_and_idempotency(): void {
        $this->setupRoot();
        $this->postJson('/api/customers',['id'=>'C/1','name'=>'Alpha'])->assertCreated();
        $this->postJson('/api/customers',['id'=>'C/2','name'=>'Beta'])->assertCreated();
        $this->getJson('/api/customers?q=Alpha&perPage=1')->assertOk()->assertJsonPath('meta.total',1)->assertJsonPath('data.0.name','Alpha');
        $this->patchJson('/api/customers/C%2F1',['_version'=>1,'phone'=>'123'])->assertOk()->assertJsonPath('data._version',2);
        $this->patchJson('/api/customers/C%2F1',['_version'=>1,'phone'=>'456'])->assertConflict();
        $this->withHeader('Idempotency-Key','rollback-123')->postJson('/api/transactions',['operations'=>[
            ['resource'=>'customers','action'=>'update','id'=>'C/1','version'=>2,'data'=>['name'=>'Changed']],
            ['resource'=>'customers','action'=>'create','data'=>['id'=>'C3','email'=>'invalid']]
        ]])->assertUnprocessable();
        $this->assertSame('Alpha',Customer::find('C/1')->data['name']);
        $body=['operations'=>[['resource'=>'customers','action'=>'update','id'=>'C/1','version'=>2,'data'=>['phone'=>'789']]]];
        $a=$this->withHeader('Idempotency-Key','repeat-key-123')->postJson('/api/transactions',$body)->assertOk()->json();
        $b=$this->withHeader('Idempotency-Key','repeat-key-123')->postJson('/api/transactions',$body)->assertOk()->json();
        $this->assertSame($a,$b);$this->assertSame(3,Customer::find('C/1')->version);
        $this->flushHeaders()->deleteJson('/api/customers/C%2F2',['_version'=>1])->assertOk();
    }
    public function test_employee_cannot_read_sensitive_directory_or_mutate_company_data(): void {
        $this->setupRoot();
        $this->postJson('/api/employees',['id'=>'E1','name'=>'Alice','department'=>'التصميم','nationalId'=>'PRIVATE','jobTitle'=>'Designer','hireDate'=>'2026-01-01','attendanceMode'=>'daily'])->assertCreated();
        $this->postJson('/api/employees',['id'=>'E2','name'=>'Bob','department'=>'المبيعات','nationalId'=>'SECRET','salary'=>3000,'jobTitle'=>'Sales','hireDate'=>'2026-01-01','attendanceMode'=>'daily'])->assertCreated();
        $this->postJson('/api/users',['name'=>'Alice','username'=>'alice','password'=>'Test-password123','roleId'=>'role_employee','employeeId'=>'E1','active'=>true,'mustChange'=>false])->assertCreated();
        $this->postJson('/api/auth/logout')->assertOk();$this->postJson('/api/auth/login',['username'=>'alice','password'=>'Test-password123'])->assertOk();
        $this->getJson('/api/employees/E2')->assertOk()->assertJsonMissingPath('data.nationalId')->assertJsonMissingPath('data.salary');
        $this->postJson('/api/customers',['name'=>'Unauthorized'])->assertForbidden();
        $this->getJson('/api/users')->assertForbidden();
        $this->patchJson('/api/employees/E1',['_version'=>1,'hrNoticeArchive'=>[['id'=>'FORGED','title'=>'Forged']]])->assertUnprocessable();
        $this->patchJson('/api/employees/E2',['_version'=>1,'salary'=>5000])->assertForbidden();
    }
}

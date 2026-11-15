<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Hash,DB};
use App\Models\{User,Employee,Customer};
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

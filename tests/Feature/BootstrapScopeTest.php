<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\{Invoice,Quotation};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

final class BootstrapScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_section_selects_its_resources_before_queries(): void
    {
        $this->seed();
        $this->postJson('/api/auth/setup',[
            'name'=>'Scope Test','username'=>'admin',
            'password'=>'Test-Password123!','password_confirmation'=>'Test-Password123!',
        ])->assertCreated();

        DB::enableQueryLog();
        $home=$this->getJson('/api/bootstrap?section=dashboard')->assertOk()->json('data.loadedResources');
        $sql=implode(' ', array_column(DB::getQueryLog(), 'query'));
        DB::disableQueryLog();
        $this->assertContains('tasks',$home);
        $this->assertNotContains('invoices',$home);
        $this->assertNotContains('quotations',$home);
        $this->assertStringNotContainsString('from "'.(new Invoice)->getTable().'"',$sql);
        $this->assertStringNotContainsString('from "'.(new Quotation)->getTable().'"',$sql);
        $this->assertContains('quotations',$this->getJson('/api/bootstrap?section=sales')->assertOk()->json('data.loadedResources'));
        $this->assertContains('invoices',$this->getJson('/api/bootstrap?section=accounting')->assertOk()->json('data.loadedResources'));
        $this->getJson('/api/bootstrap?section=unknown')->assertUnprocessable();
        $this->assertContains('invoices',$this->getJson('/api/bootstrap')->assertOk()->json('data.loadedResources'));
    }
}

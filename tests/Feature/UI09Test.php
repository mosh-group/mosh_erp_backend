<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use App\Models\{User,Employee,Setting};
class UI09Test extends TestCase
{
 use RefreshDatabase;
 private array $people=[];
 protected function setUp(): void {
  parent::setUp();$this->seed();
  $this->postJson('/api/auth/setup',['name'=>'Synthetic Admin','username'=>'admin','password'=>'Synthetic-password123','password_confirmation'=>'Synthetic-password123'])->assertCreated();
  $this->people['root']=User::first();
  foreach(['one','two','three'] as $name){
   $this->postJson('/api/employees',['id'=>'E-'.$name,'name'=>'Synthetic '.$name,'department'=>'التسويق','jobTitle'=>'Testing','hireDate'=>'2026-01-01','attendanceMode'=>'daily'])->assertCreated();
   $id=$this->postJson('/api/users',['name'=>'Synthetic '.$name,'username'=>'user_'.$name,'password'=>'Synthetic-password123','roleId'=>'role_employee','employeeId'=>'E-'.$name,'active'=>true,'mustChange'=>false])->assertCreated()->json('data.id');
   $this->people[$name]=User::findOrFail($id);
  }
 }
 private function asUser(string $name): void {$u=$this->people[$name]->fresh();$this->actingAs($u)->withSession(['auth_version'=>$u->auth_version,'last_activity'=>time()]);}
 private function mail(string $to='two'): array {return ['kind'=>'mail','recipientIds'=>[$this->people[$to]->id],'subject'=>'Synthetic subject','body'=>'Synthetic content','attachmentKeys'=>[],'requestId'=>'qa-'.bin2hex(random_bytes(10))];}
 public function test_message_privacy_folders_replies_and_idempotency(): void {
  $this->asUser('one');$in=$this->mail();$id=$this->postJson('/api/communication/messages',$in)->assertCreated()->json('data.id');
  $this->postJson('/api/communication/messages',$in)->assertCreated()->assertJsonPath('data.id',$id);$this->assertSame(1,DB::table('communication_messages')->count());
  $folder=$this->postJson('/api/communication/folders',['name'=>'تعاميم'])->assertOk()->json('data.id');
  $this->patchJson('/api/communication/messages/'.$id,['folderId'=>$folder])->assertOk();
  $this->getJson('/api/communication/messages?kind=mail&folder='.$folder)->assertOk()->assertJsonPath('data.total',1);
  $this->asUser('two');$this->getJson('/api/communication/messages?kind=mail')->assertOk()->assertJsonPath('data.total',1);
  $this->patchJson('/api/communication/messages/'.$id,['folderId'=>$folder])->assertNotFound();
  $this->getJson('/api/communication/counts')->assertOk()->assertJsonPath('data.mail',1);
  $this->postJson('/api/communication/read',['ids'=>[$id]])->assertOk();$this->getJson('/api/communication/counts')->assertOk()->assertJsonPath('data.mail',0);
  $reply=$this->mail('one');$reply['replyTo']=$id;$this->postJson('/api/communication/messages',$reply)->assertCreated();
  $this->asUser('three');$this->getJson('/api/communication/messages/'.$id)->assertNotFound();
  $this->patchJson('/api/communication/messages/'.$id,['box'=>'trash'])->assertNotFound();
  $this->deleteJson('/api/communication/folders/'.$folder)->assertNotFound();
  $this->getJson('/api/communication/messages?kind=mail&oversight=1')->assertForbidden();
  $this->asUser('root');$this->getJson('/api/communication/messages?kind=mail&oversight=1')->assertOk()->assertJsonPath('data.total',2);
  $this->getJson('/api/communication/messages/'.$id)->assertOk()->assertJsonPath('data.readOnly',true);
  $this->assertTrue(DB::table('audit_events')->where('action','oversight-read')->exists());
  $this->patchJson('/api/communication/messages/'.$id,['box'=>'trash'])->assertNotFound();
 }
 public function test_chat_limits_validation_and_private_drafts(): void {
  $this->asUser('one');$in=$this->mail();$in['kind']='chat';$in['subject']='';
  $this->postJson('/api/communication/messages',$in)->assertCreated();
  $this->getJson('/api/communication/messages?kind=chat')->assertOk()->assertJsonPath('data.total',1);
  $in['requestId']='next-'.bin2hex(random_bytes(8));$in['recipientIds'][]=$this->people['three']->id;$this->postJson('/api/communication/messages',$in)->assertUnprocessable();
  $empty=$this->mail();unset($empty['body']);$this->postJson('/api/communication/messages',$empty)->assertUnprocessable()->assertJsonValidationErrors('body');
  $bad=$this->mail();$bad['attachmentKeys']=['missing'];$this->postJson('/api/communication/messages',$bad)->assertUnprocessable();
  $draft=$this->postJson('/api/communication/drafts',['subject'=>'Draft','body'=>'Unsent'])->assertOk()->json('data.id');
  $this->asUser('two');$this->getJson('/api/communication/drafts')->assertOk()->assertJsonCount(0,'data');
  $this->postJson('/api/communication/drafts',['id'=>$draft,'body'=>'stolen'])->assertNotFound();
 }
 public function test_archiving_hides_directory_disables_login_preserves_history_and_restores_inactive(): void {
  $this->asUser('root');$u=$this->people['one'];$p=Employee::findOrFail('E-one');
  $history=[['id'=>'history-one','date'=>'2026-10-01','inTime'=>'09:00','outTime'=>'17:00','hours'=>8]];
  $data=$p->data;$data['attendanceRecords']=$history;$data['statusHistory']=[['status'=>'active','note'=>'preserved']];$p->update(['data'=>$data]);
  Setting::whereKey('department-managers')->update(['value'=>json_encode(['التسويق'=>$p->id])]);
  $in=['employeeId'=>$p->id,'action'=>'archive','_version'=>$p->version,'requestId'=>'archive-qa-one'];
  $this->postJson('/api/hr/employee-lifecycle',$in)->assertOk();
  $this->postJson('/api/hr/employee-lifecycle',$in)->assertOk();
  \App\Support\R19Store::put('r29:hr:'.$p->id,['career'=>[['id'=>'scheduled-qa','type'=>'promotion','effectiveDate'=>'2020-01-01','department'=>'التسويق','jobTitle'=>'Must not apply while archived','authorizedById'=>$this->people['root']->id,'status'=>'scheduled','before'=>[]]]],0);
  \App\Services\R29\HrWorkflow::applyDue();
  $this->assertSame('Testing',$p->fresh()->data['jobTitle']);$this->assertSame('needs_hr',\App\Services\R29\HrWorkflow::state($p->id)['career'][0]['status']);
  $this->assertNotNull(Employee::findOrFail($p->id)->data['archivedAt']);$this->assertFalse($u->fresh()->active);
  $boot=$this->getJson('/api/bootstrap')->assertOk()->json('data.state.staffProfiles');$this->assertNotContains($p->id,array_column($boot,'id'));
  $this->getJson('/api/hr/archived-employees')->assertOk()->assertJsonCount(1,'data');
  $this->assertSame($history,$p->fresh()->data['attendanceRecords']);
  $this->assertSame('preserved',$p->fresh()->data['statusHistory'][0]['note']);
  $detail=\App\Services\R29\HrWorkflow::read($this->people['root'],$p->id);
  $this->assertSame($history,$detail['attendance']);$this->assertTrue($detail['capabilities']['contracts']);$this->assertFalse($detail['capabilities']['edit']);
  $this->assertNotContains($p->id,array_column(\App\Services\R29\HrWorkflow::index($this->people['root'])['employees'],'id'));
  $this->patchJson('/api/employees/'.$p->id,['_version'=>$p->fresh()->version,'active'=>true])->assertUnprocessable();
  $this->postJson('/api/hr/employee-lifecycle',['employeeId'=>$p->id,'action'=>'restore','_version'=>$p->fresh()->version,'requestId'=>'restore-qa-one'])->assertOk();
  $this->assertFalse(Employee::findOrFail($p->id)->data['active']);$this->assertFalse($u->fresh()->active);
  $this->getJson('/api/hr/archived-employees')->assertOk()->assertJsonCount(0,'data');
  $this->asUser('two');$this->postJson('/api/hr/employee-lifecycle',['employeeId'=>'E-three','action'=>'archive','_version'=>1,'requestId'=>'forbidden-archive'])->assertForbidden();
 }

 public function test_activation_returns_individual_field_errors_and_folder_removal_keeps_mail(): void {
  $this->asUser('root');
  $this->postJson('/api/employees',['id'=>'E-incomplete','name'=>'Incomplete','department'=>'التسويق','employmentStatus'=>'active','attendanceMode'=>'fingerprint'])->assertUnprocessable()->assertJsonValidationErrors(['jobTitle','hireDate','zktecoEnrollmentNumber','workdayHours']);
  $this->asUser('one');$id=$this->postJson('/api/communication/messages',$this->mail())->assertCreated()->json('data.id');
  $folder=$this->postJson('/api/communication/folders',['name'=>'Temporary'])->assertOk()->json('data.id');
  $this->patchJson('/api/communication/messages/'.$id,['folderId'=>$folder])->assertOk();
  $this->deleteJson('/api/communication/folders/'.$folder)->assertOk();
  $this->getJson('/api/communication/messages?kind=mail&folder=sent')->assertOk()->assertJsonPath('data.total',1);
 }

 public function test_attachment_access_forwarding_and_audited_oversight(): void {
  $this->asUser('one');
  \Illuminate\Support\Facades\Storage::fake('local');
  $key=$this->post('/api/files',['file'=>\Illuminate\Http\UploadedFile::fake()->createWithContent('qa.txt','Synthetic attachment only')],['Accept'=>'application/json'])->assertCreated()->json('data.key');
  $mail=$this->mail();$mail['attachmentKeys']=[$key];$this->postJson('/api/communication/messages',$mail)->assertCreated();
  $this->asUser('three');$this->getJson('/api/files/'.$key)->assertNotFound();
  $mail=$this->mail('one');$mail['attachmentKeys']=[$key];$this->postJson('/api/communication/messages',$mail)->assertForbidden();
  $this->asUser('root');$this->getJson('/api/files/'.$key)->assertOk();$this->assertTrue(DB::table('audit_events')->where('action','oversight-attachment')->exists());
  $mail['requestId']='root-forward-forbidden';$this->postJson('/api/communication/messages',$mail)->assertForbidden();
  $this->asUser('two');$this->get('/api/files/'.$key.'/content')->assertOk();
  $mail=$this->mail('three');$mail['attachmentKeys']=[$key];$this->postJson('/api/communication/messages',$mail)->assertCreated();
  $this->asUser('three');$this->getJson('/api/files/'.$key)->assertOk();
 }
}

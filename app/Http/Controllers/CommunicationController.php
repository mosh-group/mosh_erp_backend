<?php
namespace App\Http\Controllers;
use App\Models\{User,StoredFile,FileLink};
use App\Support\{Audit,R19Store};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Validator};
use Illuminate\Support\Str;

class CommunicationController extends Controller
{
 private static function guard(User $u): void {abort_unless($u->active&&!$u->must_change,403);}
 public static function canRead(User $u,string $id): bool {
  return $u->active&&!$u->must_change&&(DB::table('communication_participants')->where(['message_id'=>$id,'user_id'=>$u->id])->exists()||$u->globalPermission('communicationOversight'));
 }
 public static function fileAllowed(User $u,string $id): bool {
  if(!self::canRead($u,$id))return false;
  if(!DB::table('communication_participants')->where(['message_id'=>$id,'user_id'=>$u->id])->exists())Audit::record($u,'security','communication',$id,'oversight-attachment');
  return true;
 }
 private function query(User $u,bool $oversight=false) {
  $q=DB::table('communication_messages as m');
  if($oversight){abort_unless($u->globalPermission('communicationOversight'),403);return $q->select('m.*');}
  return $q->join('communication_participants as p','p.message_id','=','m.id')->where('p.user_id',$u->id)
   ->select('m.*','p.box','p.folder_id','p.starred','p.read_at','p.is_sender');
 }
 private function document(object $m,?array $readBy=null): array {
  $d=(array)$m;$d['recipients']=json_decode($m->recipients,true);$d['attachments']=json_decode($m->attachments,true);
  $d['starred']=(bool)($d['starred']??false);$d['read_at']=$d['read_at']??null;
  $d['readBy']=$readBy??DB::table('communication_participants')->where('message_id',$m->id)->where('is_sender',false)->whereNotNull('read_at')->pluck('read_at','user_id')->all();
  return $d;
 }
 public function directory(Request $r) {
  self::guard($r->user());
  return $this->ok(['people'=>User::where('active',true)->where('id','!=',$r->user()->id)->orderBy('name')->get(['id','name','department'])->toArray(),
   'canOversee'=>$r->user()->globalPermission('communicationOversight')]);
 }
 public function counts(Request $r) {
  self::guard($r->user());$q=$this->query($r->user())->whereNull('p.read_at')->where('p.is_sender',false)->where('p.box','!=','trash');
  $user=$r->user();
  $tasks=$user->employee_id ? DB::table('tasks')->where('employee_id',$user->employee_id)->where('status','in_progress')->whereNull('data->deletedAt')->get(['id','department','version'])->filter(fn($t)=>$user->allows(\App\Models\User::departmentApp((string)$t->department),'details'))->map(fn($t)=>['id'=>(string)$t->id,'version'=>(int)$t->version])->values() : [];
  return $this->ok(['assignedTasks'=>$tasks,'chat'=>(clone $q)->where('m.kind','chat')->count(),'mail'=>(clone $q)->where('m.kind','mail')->count()]);
 }
 public function index(Request $r) {
  $u=$r->user();self::guard($u);$v=$r->validate(['kind'=>'required|in:chat,mail','folder'=>'nullable|string|max:80','peer'=>'nullable|string|max:190','q'=>'nullable|string|max:200','page'=>'nullable|integer|min:1','oversight'=>'nullable|boolean']);
  $over=$r->boolean('oversight');$q=$this->query($u,$over)->where('m.kind',$v['kind']);
  if($v['kind']==='chat'&&!$over){
   if($peer=$v['peer']??''){$ids=[(string)$u->id,$peer];sort($ids);$q->where('m.thread_key',hash('sha256',implode('|',$ids)));}
   else $q->whereIn('m.id',DB::table('communication_messages as c')->join('communication_participants as cp','cp.message_id','=','c.id')->where('cp.user_id',$u->id)->where('c.kind','chat')->groupBy('c.thread_key')->selectRaw('MAX(c.id)'));
  }
  if($v['kind']==='mail'&&!$over){
   $folder=$v['folder']??'inbox';
   if($folder==='starred')$q->where('p.starred',true)->where('p.box','!=','trash');
   elseif(in_array($folder,['inbox','sent','archive','trash'],true))$q->where('p.box',$folder)->whereNull('p.folder_id');
   else{$this->folder($u,$folder);$q->where('p.folder_id',$folder)->where('p.box','!=','trash');}
  }
  if($search=trim($v['q']??''))$q->where(fn($s)=>$s->where('m.subject','like','%'.$search.'%')->orWhere('m.body','like','%'.$search.'%')->orWhere('m.sender_name','like','%'.$search.'%'));
  $page=$q->orderByDesc('m.id')->paginate(40);
  $reads=[];foreach(DB::table('communication_participants')->whereIn('message_id',array_map(fn($m)=>$m->id,$page->items()))->where('is_sender',false)->whereNotNull('read_at')->get(['message_id','user_id','read_at'])as$read)$reads[$read->message_id][$read->user_id]=$read->read_at;
  if($over)Audit::record($u,'security','communication','search','oversight-list',null,['kind'=>$v['kind'],'page'=>$page->currentPage()]);
  return $this->ok(['items'=>array_map(fn($m)=>$this->document($m,$reads[$m->id]??[]),$page->items()),'page'=>$page->currentPage(),'lastPage'=>$page->lastPage(),'total'=>$page->total()]);
 }
 public function show(Request $r,string $id) {
  $u=$r->user();self::guard($u);abort_unless(self::canRead($u,$id),404);
  $own=DB::table('communication_participants')->where(['message_id'=>$id,'user_id'=>$u->id])->exists();
  $m=$this->query($u,!$own)->where('m.id',$id)->first();abort_unless($m,404);
  if(!$own)Audit::record($u,'security','communication',$id,'oversight-read');
  return $this->ok($this->document($m)+['readOnly'=>!$own]);
 }
 private function attachments(User $u,array $keys): array {
  $out=[];
  foreach(array_unique($keys) as $key){
   $f=StoredFile::where('logical_key',$key)->first();abort_unless($f,422,'المرفق غير موجود.');
   $allowed=(string)$f->owner_id===(string)$u->id;
   if(!$allowed)foreach(FileLink::where('file_id',$f->id)->where('resource','communication')->get() as $link) {
    // Forwarding requires participation, never the oversight permission alone.
    if(DB::table('communication_participants')->where(['message_id'=>$link->record_id,'user_id'=>$u->id])->exists())$allowed=true;
   }
   abort_unless($allowed,403,'المرفق ليس متاحاً للإرسال.');$out[]=$f;
  }
  return $out;
 }
 public function send(Request $r) {
  $u=$r->user();self::guard($u);
  $v=$r->validate(['kind'=>'required|in:chat,mail','recipientIds'=>'required|array|min:1|max:50','recipientIds.*'=>'required|string|distinct|max:190',
   'subject'=>'required_if:kind,mail|nullable|string|max:250','body'=>'nullable|string|max:20000','attachmentKeys'=>'sometimes|array|max:20','attachmentKeys.*'=>'string|max:190',
   'replyTo'=>'nullable|string|max:40','draftId'=>'nullable|string|max:40','requestId'=>'required|string|min:8|max:128']);
  if(trim((string)($v['body']??''))===''&&empty($v['attachmentKeys']))throw \Illuminate\Validation\ValidationException::withMessages(['body'=>'اكتب رسالة أو أرفق ملفاً.']);
  $result=R19Store::commit($u,'communication-send',$v,function($tx)use($u,$v){
   abort_if($v['kind']==='chat'&&count($v['recipientIds'])!==1,422,'اختر موظفاً واحداً للدردشة.');
   $recipients=User::whereIn('id',$v['recipientIds'])->where('active',true)->get();
   abort_unless($recipients->count()===count($v['recipientIds']),422,'أحد المستلمين غير متاح.');
   abort_if(in_array((string)$u->id,$v['recipientIds'],true),422,'اختر موظفاً آخر.');
   $files=$this->attachments($u,$v['attachmentKeys']??[]);$id=(string)Str::ulid();$thread=$id;
   if($v['kind']==='chat'){$ids=[(string)$u->id,(string)$recipients->first()->id];sort($ids);$thread=hash('sha256',implode('|',$ids));}
   if($parent=$v['replyTo']??''){
    abort_unless(DB::table('communication_participants')->where(['message_id'=>$parent,'user_id'=>$u->id])->exists(),404);
    $old=DB::table('communication_messages')->where('id',$parent)->first();abort_unless($old&&$old->kind===$v['kind'],422);if($v['kind']==='chat')abort_unless($old->thread_key===$thread,422);else $thread=$old->thread_key;
   }
   DB::table('communication_messages')->insert(['id'=>$id,'kind'=>$v['kind'],'thread_key'=>$thread,'sender_id'=>$u->id,'sender_name'=>$u->name,
    'subject'=>$v['subject']??'','body'=>$v['body']??'','recipients'=>json_encode($recipients->map(fn($p)=>['id'=>$p->id,'name'=>$p->name])->all(),JSON_UNESCAPED_UNICODE),
    'attachments'=>json_encode(array_map(fn($f)=>$f->document(),$files),JSON_UNESCAPED_UNICODE),'reply_to'=>$v['replyTo']??null,'created_at'=>now(),'updated_at'=>now()]);
   foreach(array_merge([$u],$recipients->all()) as $person)DB::table('communication_participants')->insert(['message_id'=>$id,'user_id'=>$person->id,'is_sender'=>$person->id===$u->id,'box'=>$person->id===$u->id?'sent':'inbox','read_at'=>$person->id===$u->id?now():null]);
   foreach($files as $f)FileLink::firstOrCreate(['file_id'=>$f->id,'resource'=>'communication','record_id'=>$id]);
   if($draft=$v['draftId']??'')DB::table('communication_drafts')->where(['id'=>$draft,'user_id'=>$u->id])->delete();
   Audit::record($u,'communication','communication',$id,'send',null,['kind'=>$v['kind'],'recipientCount'=>count($v['recipientIds'])],$tx);
   return ['id'=>$id];
  });return $this->ok($this->document($this->query($u)->where('m.id',$result['id'])->first()),201);
 }
 public function read(Request $r) {
  self::guard($r->user());$v=$r->validate(['ids'=>'required|array|max:100','ids.*'=>'string|max:40']);
  DB::table('communication_participants')->where('user_id',$r->user()->id)->whereIn('message_id',$v['ids'])->whereNull('read_at')->update(['read_at'=>now()]);
  return $this->ok(['saved'=>true]);
 }
 private function folder(User $u,string $id) {return DB::table('communication_folders')->where(['id'=>$id,'user_id'=>$u->id])->first()??abort(404);}
 public function state(Request $r,string $id) {
  $u=$r->user();self::guard($u);$v=$r->validate(['folderId'=>'sometimes|nullable|string|max:40','box'=>'sometimes|in:inbox,sent,archive,trash','starred'=>'sometimes|boolean','unread'=>'sometimes|boolean']);
  $p=DB::table('communication_participants')->where(['message_id'=>$id,'user_id'=>$u->id])->first();abort_unless($p,404);
  abort_unless(DB::table('communication_messages')->where(['id'=>$id,'kind'=>'mail'])->exists(),422);
  $data=[];if(array_key_exists('folderId',$v)){$folder=$v['folderId']?:null;if($folder)$this->folder($u,$folder);$data['folder_id']=$folder;$data['box']=$p->is_sender?'sent':'inbox';}
  if(isset($v['box'])){$data['box']=in_array($v['box'],['inbox','sent'])?($p->is_sender?'sent':'inbox'):$v['box'];$data['folder_id']=null;}
  if(isset($v['starred']))$data['starred']=$v['starred'];if(isset($v['unread']))$data['read_at']=$v['unread']?null:now();
  if($data)DB::table('communication_participants')->where('id',$p->id)->update($data);
  return $this->ok(['saved'=>true]);
 }
 public function folders(Request $r) {self::guard($r->user());return $this->ok(DB::table('communication_folders')->where('user_id',$r->user()->id)->orderBy('name')->get());}
 public function saveFolder(Request $r,string $id='') {
  $u=$r->user();self::guard($u);$v=$r->validate(['name'=>'required|string|max:80']);$name=trim($v['name']);abort_if($name==='',422,'اكتب اسم المجلد.');
  if($id)$this->folder($u,$id);abort_if(DB::table('communication_folders')->where(['user_id'=>$u->id,'name'=>$name])->when($id,fn($q)=>$q->where('id','!=',$id))->exists(),422,'اسم المجلد موجود.');
  $id=$id?:(string)Str::ulid();DB::table('communication_folders')->updateOrInsert(['id'=>$id,'user_id'=>$u->id],['name'=>$name,'updated_at'=>now(),'created_at'=>now()]);return $this->ok(['id'=>$id,'name'=>$name]);
 }
 public function deleteFolder(Request $r,string $id) {
  $u=$r->user();self::guard($u);$this->folder($u,$id);
  DB::transaction(function()use($u,$id){DB::table('communication_participants')->where(['user_id'=>$u->id,'folder_id'=>$id])->update(['folder_id'=>null]);DB::table('communication_folders')->where(['user_id'=>$u->id,'id'=>$id])->delete();});
  return $this->ok(['saved'=>true]);
 }
 public function drafts(Request $r) {self::guard($r->user());return $this->ok(DB::table('communication_drafts')->where('user_id',$r->user()->id)->orderByDesc('updated_at')->get()->map(fn($d)=>['id'=>$d->id,'data'=>json_decode($d->data,true),'updated_at'=>$d->updated_at]));}
 public function saveDraft(Request $r) {
  $u=$r->user();self::guard($u);$v=$r->validate(['id'=>'nullable|string|max:40','subject'=>'nullable|string|max:250','body'=>'nullable|string|max:20000','recipientIds'=>'sometimes|array|max:50','recipientIds.*'=>'string|max:190','attachmentKeys'=>'sometimes|array|max:20','attachmentKeys.*'=>'string|max:190','replyTo'=>'nullable|string|max:40']);
  if($id=$v['id']??'')abort_unless(DB::table('communication_drafts')->where(['id'=>$id,'user_id'=>$u->id])->exists(),404);
  $this->attachments($u,$v['attachmentKeys']??[]);$id=$id?:(string)Str::ulid();unset($v['id']);
  DB::table('communication_drafts')->updateOrInsert(['id'=>$id,'user_id'=>$u->id],['data'=>json_encode($v,JSON_UNESCAPED_UNICODE),'created_at'=>now(),'updated_at'=>now()]);return $this->ok(['id'=>$id]);
 }
 public function deleteDraft(Request $r,string $id) {self::guard($r->user());DB::table('communication_drafts')->where(['id'=>$id,'user_id'=>$r->user()->id])->delete();return $this->ok(['saved'=>true]);}
}

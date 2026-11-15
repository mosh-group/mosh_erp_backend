<?php
namespace App\Http\Controllers;
use App\Models\StoredFile;
use App\Support\R19Store;
use App\Services\Instagram\{SocialHub, SocialAccess, SocialPolicy, SocialSettings, SocialWorker, MetaException};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
final class InstagramController extends Controller
{
    public function __construct(private SocialHub $hub,private SocialWorker $worker){}
    public function index(Request $r){$v=$r->validate(['customerId'=>'required|string|max:190']);return $this->ok($this->hub->list($r->user(),$v['customerId']));}
    public function configuration(Request $r){
        abort_unless($r->user()->root(),403);
        if($r->isMethod('GET'))return $this->ok(SocialSettings::publicConfig(true));
        abort_unless($r->isSecure(),422,'افتح النظام عبر HTTPS لحفظ سر تطبيق Instagram.');
        $v=$r->validate(['appId'=>['required','string','regex:/^\d+$/D'],'appSecret'=>'nullable|string|max:500',
            'redirectUri'=>'required|url:https|max:2000','publicBaseUrl'=>'required|url:https|max:2000','graphVersion'=>['required','string','regex:/^v\d{1,2}\.0$/D'],
            '_version'=>'required|integer|min:0','requestId'=>'required|string|min:8|max:128']);return $this->ok(SocialSettings::save($r->user(),$v));
    }
    public function start(Request $r){
        $v=$r->validate(['customerId'=>'required|string|max:190']);$actor=$r->user();SocialAccess::customer($actor,$v['customerId'],'edit');
        abort_unless(SocialAccess::permission($actor,'connectInstagram'),403);$c=SocialSettings::privateConfig();
        abort_unless(SocialSettings::configured($c),409,'إعداد تطبيق Meta غير مكتمل. افتح إعدادات Instagram من الحساب الأساسي.');
        $nonce=bin2hex(random_bytes(32));$key=hash('sha256',$nonce);
        $states=array_filter($r->session()->get('mosh_ig_states',[]),fn($s)=>($s['expires']??0)>time());
        $states=array_slice($states,-4,null,true);$states[$key]=['customerId'=>$v['customerId'],'userId'=>(string)$actor->id,'expires'=>time()+600,'requestId'=>(string)Str::uuid()];
        $r->session()->put('mosh_ig_states',$states);
        return $this->ok(['authorizationUrl'=>'https://www.instagram.com/oauth/authorize?'.http_build_query(['enable_fb_login'=>'0','force_authentication'=>'1','client_id'=>$c['appId'],
            'redirect_uri'=>$c['redirectUri'],'response_type'=>'code','scope'=>implode(',',SocialPolicy::SCOPES),'state'=>$nonce], '', '&', PHP_QUERY_RFC3986)]);
    }
    public function callback(Request $r){
        $nonce=$r->query('state','');$success=false;$message='لم يتم الربط. أعد المحاولة من داخل موش.';
        if(is_string($nonce)&&preg_match('/^[a-f0-9]{64}$/D',$nonce)){
            $key=hash('sha256',$nonce);$states=$r->session()->get('mosh_ig_states',[]);$saved=$states[$key]??null;
            unset($states[$key]);$r->session()->put('mosh_ig_states',$states); // Single use, including a declined request.
            if($saved&&($saved['expires']??0)>=time()&&($saved['userId']??'')===(string)$r->user()->id){
                if($r->query('error'))$message='أُلغي تفويض Instagram. لم يتغير الحساب في موش.';
                elseif(is_string($r->query('code'))&&strlen($r->query('code'))<4096){
                    try{$this->hub->connect($r->user(),$saved['customerId'],$r->query('code'),$saved['requestId']);$success=true;$message='تم ربط Instagram. أغلق هذه النافذة ثم حدّث قسم السوشال ميديا في موش.';}
                    catch(MetaException $e){$message=$e->getMessage();}catch(\Throwable $e){$message='تعذر حفظ الربط. تحقق من حساب العميل وصلاحيات تطبيق Meta، ثم أعد المحاولة.';}
                }
            }
        }
        $text=htmlspecialchars($message,ENT_QUOTES,'UTF-8');$title=$success?'تم الربط':'لم يكتمل الربط';
        return response('<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>'.$title.'</title><body><main><h1>'.$title.'</h1><p>'.$text.'</p></main></body></html>', $success?200:400)
            ->header('Content-Type','text/html; charset=UTF-8')->header('Cache-Control','no-store')->header('Referrer-Policy','no-referrer')
            ->header('Content-Security-Policy',"default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
    }
    public function disconnect(Request $r,string $id){$v=$r->validate(['_version'=>'required|integer|min:1','requestId'=>'required|string|min:8|max:128']);return $this->ok($this->hub->disconnect($r->user(),$id,$v));}
    public function savePost(Request $r,?string $id=null){
        $v=$r->validate(['customerId'=>$id?'sometimes|string|max:190':'required|string|max:190','accountId'=>'nullable|string|max:40','caption'=>'present|nullable|string|max:2200',
            'mediaKind'=>'required|in:image,reel','attachmentKey'=>'nullable|string|max:190','mode'=>'required|in:draft,publish,schedule','scheduledAt'=>'nullable|date',
            '_version'=>$id?'required|integer|min:1':'sometimes|integer|min:0','requestId'=>'required|string|min:8|max:128']);
        $p=$this->hub->savePost($r->user(),$v,$id);
        if($p['status']==='queued')$p=$this->worker->process($p['id']);
        return $this->ok($p,$id?200:201);
    }
    public function decide(Request $r,string $id){
        $v=$r->validate(['action'=>'required|in:approve,reject,return,cancel','note'=>'required_if:action,reject,return|nullable|string|max:2000','_version'=>'required|integer|min:1','requestId'=>'required|string|min:8|max:128']);
        $p=$this->hub->decide($r->user(),$id,$v);if($p['status']==='queued')$p=$this->worker->process($p['id']);return $this->ok($p);
    }
    public function check(Request $r,string $id){
        $p=SocialHub::post($id);SocialAccess::customer($r->user(),$p['customerId']);return $this->ok($this->worker->process($id));
    }
    public function insights(Request $r,string $id){
        $v=$r->validate(['days'=>'sometimes|integer|in:7,30','postId'=>'nullable|string|max:40']);
        try{return $this->ok($this->hub->insights($r->user(),$id,(int)($v['days']??7),$v['postId']??null));}catch(MetaException $e){abort(502,$e->getMessage());}
    }
    // Public only for a scoped, short-lived signature created by the worker.
    public function media(Request $r,string $id){
        $expires=$r->query('expires');$signature=$r->query('signature');
        abort_unless(is_string($expires)&&ctype_digit($expires)&&(int)$expires>=time()&&(int)$expires<=time()+7300&&is_string($signature)&&preg_match('/^[a-f0-9]{64}$/D',$signature),404);
        $p=SocialHub::post($id);$expected=hash_hmac('sha256',$id.'|'.($p['attachmentKey']??'').'|'.$expires,(string)config('app.key'));
        abort_unless(hash_equals($expected,$signature)&&in_array($p['status'],array_merge(SocialPolicy::WORKABLE,['published']),true),404);
        $f=StoredFile::where('logical_key',$p['attachmentKey'])->firstOrFail();abort_unless(in_array($f->mime,['image/jpeg','video/mp4'],true)&&Storage::disk('local')->exists($f->path),404);
        return Storage::disk('local')->response($f->path,$f->name,['Content-Type'=>$f->mime,'X-Content-Type-Options'=>'nosniff','Cache-Control'=>'private, no-store','Referrer-Policy'=>'no-referrer'],'inline');
    }
    public function revoke(Request $r){$v=$r->validate(['signed_request'=>'required|string|max:10000']);SocialHub::providerRevocation($v['signed_request']);return response()->json(['success'=>true]);}
    public function erase(Request $r){$v=$r->validate(['signed_request'=>'required|string|max:10000']);return response()->json(SocialHub::providerRevocation($v['signed_request'],true));}
    public function deletionStatus(Request $r,string $id){$v=R19Store::get('r28:social:deletion:'.$id);abort_unless($v,404);return response()->json(['status'=>'completed','confirmation_code'=>$id])->header('Cache-Control','no-store');}
}

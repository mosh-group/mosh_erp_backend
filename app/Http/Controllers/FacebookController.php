<?php
namespace App\Http\Controllers;
use App\Services\Social\{FacebookHub,FacebookWorker,FacebookSettings,FacebookException,Registry};
use App\Services\Instagram\SocialAccess;
use App\Support\R19Store;
use App\Models\StoredFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
final class FacebookController extends Controller
{
    public function __construct(private FacebookHub$hub,private FacebookWorker$worker){}
    public function index(Request$r){$v=$r->validate(['customerId'=>'required|string|max:190']);return$this->ok($this->hub->list($r->user(),$v['customerId']));}
    public function platforms(Request$r){abort_unless($r->user()->active,403);return$this->ok(['platforms'=>Registry::platforms()]);}
    public function configuration(Request$r){abort_unless($r->user()->root(),403);if($r->isMethod('GET'))return$this->ok(FacebookSettings::public(true));abort_unless($r->isSecure(),422,'يلزم HTTPS لحفظ سر تطبيق Facebook.');
        $v=$r->validate(['appId'=>['required','string','regex:/^\d+$/D'],'appSecret'=>'nullable|string|max:500','redirectUri'=>'required|url:https|max:2000','publicBaseUrl'=>'required|url:https|max:2000','graphVersion'=>['required','string','regex:/^v\d{1,2}\.0$/D'],'_version'=>'required|integer|min:0','requestId'=>'required|string|min:8|max:128']);return$this->ok(FacebookSettings::save($r->user(),$v));}
    public function start(Request$r){abort_unless($r->isSecure(),422,'الربط الرسمي يتطلب فتح موش عبر HTTPS.');$v=$r->validate(['customerId'=>'required|string|max:190']);return$this->ok($this->hub->start($r->user(),$v['customerId'],$r->session()->getId()));}
    public function callback(Request$r){$success=false;$message='لم يكتمل التفويض. أعد الربط من داخل موش.';
        try{$state=$r->query('state','');$code=$r->query('code','');abort_unless(is_string($state)&&is_string($code)&&strlen($code)<4096,400);$declined=(bool)$r->query('error');$this->hub->callback($r->user(),$state,$code,$r->session()->getId(),$declined);$success=!$declined;$message=$success?'اكتمل التفويض. أغلق النافذة، واضغط «اختيار الصفحة بعد التفويض» في موش.':'رُفض التفويض؛ لم يتغير اتصال أي صفحة.';}
        catch(\Throwable$e){if($e instanceof FacebookException)$message=$e->getMessage();}
        return response('<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>ربط Facebook</title><body><h1>ربط Facebook</h1><p>'.htmlspecialchars($message,ENT_QUOTES,'UTF-8').'</p></body></html>',$success?200:400)->header('Content-Type','text/html; charset=UTF-8')->header('Cache-Control','no-store')->header('Referrer-Policy','no-referrer')->header('Content-Security-Policy',"default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
    }
    public function selections(Request$r){$v=$r->validate(['customerId'=>'required|string|max:190']);return$this->ok($this->hub->selections($r->user(),$v['customerId'],$r->session()->getId()))->header('Cache-Control','no-store, private');}
    public function choose(Request$r){$v=$r->validate(['selectionId'=>['required','string','regex:/^[a-f0-9]{64}$/D'],'pageId'=>['required','string','regex:/^\d{1,40}$/D'],'_version'=>'required|integer|min:1','requestId'=>'required|string|min:8|max:128']);return$this->ok($this->hub->select($r->user(),$v,$r->session()->getId()));}
    public function disconnect(Request$r,string$id){$v=$r->validate(['_version'=>'required|integer|min:1','requestId'=>'required|string|min:8|max:128']);return$this->ok($this->hub->disconnect($r->user(),$id,$v));}
    public function save(Request$r,?string$id=null){$v=$r->validate(['customerId'=>$id?'sometimes|string|max:190':'required|string|max:190','accountId'=>'nullable|string|max:40','caption'=>'present|nullable|string|max:60000','mediaKind'=>'required|in:text,image,video','attachmentKey'=>'nullable|string|max:190','mode'=>'required|in:draft,publish,schedule','scheduledAt'=>'nullable|date','_version'=>$id?'required|integer|min:1':'sometimes|integer|min:0','requestId'=>'required|string|min:8|max:128']);$p=$this->hub->save($r->user(),$v,$id);if($p['status']==='queued')$p=$this->worker->process($p['id']);return$this->ok($p,$id?200:201);}
    public function decision(Request$r,string$id){$v=$r->validate(['action'=>'required|in:approve,reject,return,cancel','note'=>'required_if:action,reject,return|nullable|string|max:2000','_version'=>'required|integer|min:1','requestId'=>'required|string|min:8|max:128']);$p=$this->hub->decide($r->user(),$id,$v);if($p['status']==='queued')$p=$this->worker->process($p['id']);return$this->ok($p);}
    public function check(Request$r,string$id){$p=FacebookHub::post($id);SocialAccess::customer($r->user(),$p['customerId']);return$this->ok($this->worker->process($id));}
    public function insights(Request$r,string$id){$v=$r->validate(['days'=>'sometimes|integer|in:7,30']);try{return$this->ok($this->hub->insights($r->user(),$id,(int)($v['days']??7)));}catch(FacebookException$e){abort(502,$e->getMessage());}}
    public function media(Request$r,string$id){$expires=$r->query('expires');$signature=$r->query('signature');abort_unless(is_string($expires)&&ctype_digit($expires)&&(int)$expires>=time()&&(int)$expires<=time()+7300&&is_string($signature)&&preg_match('/^[a-f0-9]{64}$/D',$signature),404);$p=FacebookHub::post($id);
        $expected=hash_hmac('sha256','fb|'.$id.'|'.($p['attachmentKey']??'').'|'.$expires,(string)config('app.key'));abort_unless(hash_equals($expected,$signature)&&in_array($p['status'],['publishing','processing','published'],true),404);
        $a=FacebookHub::account($p['accountId']);abort_unless(FacebookHub::connected($a),404);$f=StoredFile::where('logical_key',$p['attachmentKey'])->firstOrFail();abort_unless(in_array($f->mime,['image/jpeg','image/png','video/mp4'],true)&&Storage::disk('local')->exists($f->path),404);
        return Storage::disk('local')->response($f->path,$f->name,['Content-Type'=>$f->mime,'X-Content-Type-Options'=>'nosniff','Cache-Control'=>'private, no-store','Referrer-Policy'=>'no-referrer'],'inline');}
    public function revoke(Request$r){$v=$r->validate(['signed_request'=>'required|string|max:10000']);FacebookHub::revoke($v['signed_request']);return response()->json(['success'=>true]);}
    public function erase(Request$r){$v=$r->validate(['signed_request'=>'required|string|max:10000']);return response()->json(FacebookHub::revoke($v['signed_request'],true));}
    public function deletionStatus(Request$r,string$id){abort_unless(R19Store::get('r34:facebook:deletion:'.$id),404);return response()->json(['status'=>'completed','confirmation_code'=>$id])->header('Cache-Control','no-store');}
}

<?php
namespace App\Services\Social;
use App\Models\{User,StoredFile,FileLink};
use App\Support\{R19Store,Audit};
use App\Services\Instagram\{SocialAccess,SocialPolicy};
use Illuminate\Support\Facades\{DB,Crypt,Storage,Validator};
use Illuminate\Support\Str;
final class FacebookHub
{
    public const ACCOUNTS='r34:facebook:account:';public const POSTS='r34:facebook:post:';public const OAUTH='r34:facebook:oauth:';
    public const EDITABLE=['draft','returned','cancelled','failed','blocked'];
    public const WORKABLE=['queued','scheduled','publishing','processing'];
    public function __construct(private FacebookProvider $provider){}
    public static function lock(callable $fn): mixed {return DB::transaction(function()use($fn){abort_unless(DB::table('app_locks')->where('id','business')->increment('revision')===1,503);return$fn();},3);}
    public static function permission(User $a,string $key): bool {return SocialAccess::permission($a,$key);}
    public static function capabilities(User $a): array {return ['connect'=>self::permission($a,'connectFacebook')&&SocialAccess::allows($a,'edit'),'compose'=>SocialAccess::allows($a,'create'),
        'direct'=>self::permission($a,'publishFacebookDirect'),'schedule'=>self::permission($a,'scheduleFacebookDirect'),'approve'=>self::permission($a,'approveFacebookPosts'),'insights'=>self::permission($a,'viewFacebookInsights'),'configure'=>$a->root()];}
    public static function account(string$id): array {$a=R19Store::get(self::ACCOUNTS.$id);abort_unless($a,404);return$a;}
    public static function post(string$id): array {$p=R19Store::get(self::POSTS.$id);abort_unless($p,404);return$p;}
    public static function connected(array$a): bool {return($a['status']??'')==='connected'&&!empty($a['encryptedToken'])&&(empty($a['expiresAt'])||strtotime($a['expiresAt'])>time());}
    public static function accountPublic(array$a): array {$d=array_intersect_key($a,array_flip(['id','customerId','platform','pageId','name','status','expiresAt','connectedAt','connectedBy','_version']));if(($d['status']??'')==='connected'&&!self::connected($a))$d['status']='expired';return$d;}
    public static function postPublic(array$p): array {return array_diff_key($p,array_flip(['leaseId','leaseUntil','authorizedById','authorizationKind']));}
    public static function canReadPost(User$a,string$id): bool {try{$p=self::post($id);SocialAccess::customer($a,$p['customerId']);return true;}catch(\Throwable $e){return false;}}
    public function list(User$a,string$customerId): array
    {
        SocialAccess::customer($a,$customerId);$accounts=[];$posts=[];
        foreach(R19Store::documents(self::ACCOUNTS)as$r)if($r['customerId']===$customerId)$accounts[]=self::accountPublic($r);
        foreach(R19Store::documents(self::POSTS)as$r)if($r['customerId']===$customerId)$posts[]=self::postPublic($r);
        usort($posts,fn($x,$y)=>strcmp($y['createdAt'],$x['createdAt']));
        return ['platform'=>'facebook','platforms'=>Registry::platforms(),'accounts'=>$accounts,'posts'=>$posts,'capabilities'=>self::capabilities($a),'configuration'=>FacebookSettings::public($a->root())];
    }
    public function start(User$a,string$customerId,string$session): array
    {
        SocialAccess::customer($a,$customerId,'edit');abort_unless(self::permission($a,'connectFacebook'),403);$c=FacebookSettings::get();abort_unless(FacebookSettings::configured($c),409,'أكمل إعداد تطبيق Facebook من الحساب التقني أولاً.');
        $nonce=bin2hex(random_bytes(32));$id=hash('sha256',$nonce);
        self::lock(function()use($a,$customerId,$session,$id){$a->refresh();SocialAccess::customer($a,$customerId,'edit');abort_unless(self::permission($a,'connectFacebook'),403);
            R19Store::put(self::OAUTH.$id,['id'=>$id,'userId'=>(string)$a->id,'customerId'=>$customerId,'sessionHash'=>self::sessionHash($session),'status'=>'pending','expires'=>time()+600],0);
        });
        return['authorizationUrl'=>'https://www.facebook.com/'.$c['graphVersion'].'/dialog/oauth?'.http_build_query(['client_id'=>$c['appId'],'redirect_uri'=>$c['redirectUri'],'response_type'=>'code','state'=>$nonce,'scope'=>'pages_show_list,pages_read_engagement,pages_manage_posts,read_insights','auth_type'=>'rerequest'],'','&',PHP_QUERY_RFC3986)];
    }
    private static function sessionHash(string$s): string {return hash_hmac('sha256',$s,(string)config('app.key'));}
    public function callback(User$a,string$state,string$code,string$session,bool$declined=false): void
    {
        abort_unless(preg_match('/^[a-f0-9]{64}$/D',$state),400);$id=hash('sha256',$state);
        $record=self::lock(function()use($a,$id,$session,$declined){$r=R19Store::get(self::OAUTH.$id);abort_unless($r&&$r['userId']===(string)$a->id&&hash_equals($r['sessionHash'],self::sessionHash($session)),403);
            abort_unless($r['status']==='pending'&&$r['expires']>time(),410,'انتهت صلاحية الربط أو استُخدم سابقاً.');SocialAccess::customer($a,$r['customerId'],'edit');abort_unless(self::permission($a,'connectFacebook'),403);
            $r['status']=$declined?'declined':'exchanging';return R19Store::put(self::OAUTH.$id,$r,$r['_version']);
        });if($declined)return;
        try{$profile=$this->provider->exchange($code,FacebookSettings::get());
            self::lock(function()use($a,$id,$profile){$a->refresh();$r=R19Store::get(self::OAUTH.$id);abort_unless($r&&$r['status']==='exchanging'&&$r['expires']>time(),409);SocialAccess::customer($a,$r['customerId'],'edit');abort_unless(self::permission($a,'connectFacebook'),403);
                $r['encryptedSelection']=Crypt::encryptString(json_encode($profile,JSON_THROW_ON_ERROR));$r['status']='ready';$r['expires']=time()+600;R19Store::put(self::OAUTH.$id,$r,$r['_version']);
            });
        }catch(\Throwable$e){self::lock(function()use($id){$r=R19Store::get(self::OAUTH.$id);if($r&&$r['status']==='exchanging'){$r['status']='failed';R19Store::put(self::OAUTH.$id,$r,$r['_version']);}});throw$e;}
    }
    public function selections(User$a,string$customerId,string$session): array
    {
        SocialAccess::customer($a,$customerId,'edit');abort_unless(self::permission($a,'connectFacebook'),403);$out=[];
        foreach(R19Store::documents(self::OAUTH)as$r)if($r['userId']===(string)$a->id&&$r['customerId']===$customerId&&$r['status']==='ready'&&$r['expires']>time()&&hash_equals($r['sessionHash'],self::sessionHash($session))){
            $d=json_decode(Crypt::decryptString($r['encryptedSelection']),true,512,JSON_THROW_ON_ERROR);
            $out[]=['id'=>$r['id'],'_version'=>$r['_version'],'pages'=>array_map(fn($p)=>['pageId'=>$p['pageId'],'name'=>$p['name']],$d['pages']),'expiresAt'=>gmdate('c',$r['expires'])];
        }return['selections'=>$out];
    }
    public function select(User$a,array$in,string$session): array
    {
        $res=R19Store::commit($a,'facebook:select-page',$in,function($tx)use($a,$in,$session){
            $r=R19Store::get(self::OAUTH.$in['selectionId']);abort_unless($r&&$r['userId']===(string)$a->id&&hash_equals($r['sessionHash'],self::sessionHash($session)),403);
            abort_unless($r['status']==='ready'&&$r['expires']>time()&&$r['_version']===(int)$in['_version'],409,'أعد التفويض؛ انتهت صلاحية اختيار الصفحة.');SocialAccess::customer($a,$r['customerId'],'edit');abort_unless(self::permission($a,'connectFacebook'),403);
            $profile=json_decode(Crypt::decryptString($r['encryptedSelection']),true,512,JSON_THROW_ON_ERROR);$page=null;foreach($profile['pages']as$p)if($p['pageId']===$in['pageId'])$page=$p;abort_unless($page,422,'الصفحة ليست ضمن التفويض الحالي.');
            $old=null;foreach(R19Store::documents(self::ACCOUNTS)as$existing)if(($existing['pageId']??'')===$page['pageId']){abort_unless($existing['customerId']===$r['customerId'],409,'الصفحة مرتبطة بعميل آخر؛ لم يتغير الارتباط.');$old=$existing;break;}
            $id=$old['id']??(string)Str::ulid();$d=['id'=>$id,'platform'=>'facebook','customerId'=>$r['customerId'],'pageId'=>$page['pageId'],'name'=>$page['name'],
                'subjectId'=>$profile['subjectId'],'encryptedToken'=>Crypt::encryptString($page['token']),'grants'=>$profile['grants'],'expiresAt'=>$profile['expiresAt'],
                'status'=>'connected','connectedAt'=>now()->toIso8601String(),'connectedById'=>(string)$a->id,'connectedBy'=>$a->name];
            R19Store::put(self::ACCOUNTS.$id,$d,(int)($old['_version']??0));$r['status']='used';unset($r['encryptedSelection']);R19Store::put(self::OAUTH.$r['id'],$r,$r['_version']);Audit::record($a,'marketing','facebook-account',$id,'connect',null,self::accountPublic($d),$tx);return['id'=>$id];
        });$r=self::account($res['id']);SocialAccess::customer($a,$r['customerId']);return self::accountPublic($r);
    }
    private static function invalidatePosts(string$accountId,string$message,bool$erase=false): void
    {
        foreach(R19Store::documents(self::POSTS)as$p)if($p['accountId']===$accountId){
            $p['leaseId']=null;$p['leaseUntil']=null;
            if(in_array($p['status'],self::WORKABLE,true))$p['status']=!empty($p['attemptAt'])?'uncertain':'blocked';
            if($erase)unset($p['providerId'],$p['providerPostId'],$p['permalink']);$p['lastError']=$message;R19Store::put(self::POSTS.$p['id'],$p,$p['_version']);
        }
    }
    public function disconnect(User$a,string$id,array$in): array
    {
        R19Store::commit($a,'facebook:disconnect:'.$id,$in,function($tx)use($a,$id,$in){$r=self::account($id);SocialAccess::customer($a,$r['customerId'],'edit');abort_unless(self::permission($a,'connectFacebook'),403);abort_unless($r['_version']===(int)$in['_version'],409);
            $r['status']='disconnected';unset($r['encryptedToken'],$r['grants']);R19Store::put(self::ACCOUNTS.$id,$r,$r['_version']);self::invalidatePosts($id,'فُصل اتصال الصفحة؛ الطلبات المستحقة متوقفة.');Audit::record($a,'marketing','facebook-account',$id,'disconnect',null,['status'=>'disconnected'],$tx);return['id'=>$id];});
        $r=self::account($id);SocialAccess::customer($a,$r['customerId']);return self::accountPublic($r);
    }
    private static function validateMedia(User$a,array$in,?array$old): ?StoredFile
    {
        if(($in['mediaKind']??'')==='text'){abort_if(!empty($in['attachmentKey']),422,'اختر صورة أو فيديو لإرفاق ملف.');return null;}
        $key=$in['attachmentKey']??'';if($key===''){abort_unless($in['mode']==='draft',422,'اختر ملف الوسائط أولاً.');return null;}
        $f=StoredFile::where('logical_key',$key)->firstOrFail();abort_unless($a->root()||(string)$f->owner_id===(string)$a->id||($old['attachmentKey']??null)===$key,403);
        abort_unless(Storage::disk('local')->exists($f->path)&&$f->size>0,422,'المرفق غير موجود أو فارغ.');
        abort_unless(($in['mediaKind']==='image'&&in_array($f->mime,['image/jpeg','image/png'],true))||($in['mediaKind']==='video'&&$f->mime==='video/mp4'),422,'نشر Facebook يدعم هنا JPEG/PNG أو فيديو MP4؛ مرفقات التصميم تبقى مرفقات عمل وليست وسائط نشر.');return$f;
    }
    public static function scheduled(array$input): ?string
    {
        if($input['mode']!=='schedule')return null;$n=strtotime((string)($input['scheduledAt']??''));abort_unless($n&&$n>time()+30,422,'حدد موعد نشر مستقبلياً.');return gmdate('c',$n);
    }
    public function save(User$a,array$in,?string$id=null): array
    {
        $res=R19Store::commit($a,'facebook:post:'.($id??'new'),$in,function($tx)use($a,$in,$id){
            $old=$id?self::post($id):null;$cid=$old['customerId']??$in['customerId'];SocialAccess::customer($a,$cid,'create');
            if($old){abort_unless(in_array($old['status'],self::EDITABLE,true)&&empty($old['attemptAt']),409,'لا تعدّل منشوراً بدأ تنفيذه.');abort_unless($old['_version']===(int)$in['_version'],409);abort_unless($old['createdById']===(string)$a->id||self::permission($a,'approveFacebookPosts'),403);}
            $schedule=self::scheduled($in);$file=self::validateMedia($a,$in,$old);$mode=$in['mode'];$account=null;
            if(!empty($in['accountId'])){$account=self::account($in['accountId']);abort_unless($account['customerId']===$cid,403);}
            if($mode!=='draft'){abort_unless($account&&self::connected($account),422,'اختر صفحة متصلة رسمياً.');abort_unless(trim($in['caption']??'')!==''||$file,422,'المنشور فارغ.');abort_unless(in_array('pages_manage_posts',$account['grants']??[],true),422,'تفويض الصفحة لا يحتوي صلاحية النشر.');}
            $direct=self::permission($a,$mode==='schedule'?'scheduleFacebookDirect':'publishFacebookDirect');
            if($mode==='schedule')abort_unless(FacebookSettings::public()['scheduler']['active'],409,'عامل الجدولة متوقف؛ شغّله قبل الجدولة.');
            $status=$mode==='draft'?'draft':($direct?($mode==='schedule'?'scheduled':'queued'):'pending_approval');$at=now()->toIso8601String();$pid=$id??(string)Str::ulid();
            $p=['id'=>$pid,'platform'=>'facebook','customerId'=>$cid,'accountId'=>$account['id']??'','caption'=>$in['caption']??'','mediaKind'=>$in['mediaKind'],
                'attachmentKey'=>$file?->logical_key??'','attachmentName'=>$file?->name??'','mode'=>$mode,'scheduledAt'=>$schedule,'status'=>$status,'createdById'=>$old['createdById']??(string)$a->id,
                'createdBy'=>$old['createdBy']??$a->name,'createdAt'=>$old['createdAt']??$at,'submittedById'=>(string)$a->id,'updatedAt'=>$at,
                'authorizedById'=>$mode!=='draft'&&$direct?(string)$a->id:null,'authorizationKind'=>$mode!=='draft'&&$direct?'direct':null,
                'history'=>$old['history']??[]];$p['history'][]=['at'=>$at,'by'=>$a->name,'byId'=>(string)$a->id,'action'=>$status];
            R19Store::put(self::POSTS.$pid,$p,(int)($old['_version']??0));FileLink::where(['resource'=>'r34-facebook-post','record_id'=>$pid])->delete();
            if($file)FileLink::firstOrCreate(['file_id'=>$file->id,'resource'=>'r34-facebook-post','record_id'=>$pid]);Audit::record($a,'marketing','facebook-post',$pid,'save',null,self::postPublic($p),$tx);return['id'=>$pid];
        });$p=self::post($res['id']);SocialAccess::customer($a,$p['customerId']);return self::postPublic($p);
    }
    public function decide(User$a,string$id,array$in): array
    {
        R19Store::commit($a,'facebook:decision:'.$id,$in,function($tx)use($a,$id,$in){$p=self::post($id);SocialAccess::customer($a,$p['customerId'],'edit');abort_unless($p['_version']===(int)$in['_version'],409);$action=$in['action'];$note=trim((string)($in['note']??''));
            if($action==='cancel'){abort_unless(in_array($p['status'],['draft','returned','pending_approval','queued','scheduled','failed','blocked'],true)&&empty($p['attemptAt']),409,'بدأ التنفيذ؛ لا يمكن إلغاؤه بأمان.');abort_unless($p['createdById']===(string)$a->id||self::permission($a,'approveFacebookPosts'),403);$p['status']='cancelled';}
            else{abort_unless(self::permission($a,'approveFacebookPosts')&&$p['status']==='pending_approval',403);abort_if(!$a->root()&&$p['submittedById']===(string)$a->id,403,'لا تعتمد طلبك بنفسك.');
                if($action==='approve'){if($p['mode']==='schedule'){abort_unless(strtotime($p['scheduledAt']??'')>time()+30,422,'انقضى الموعد؛ أعده للتعديل.');abort_unless(FacebookSettings::public()['scheduler']['active'],409,'عامل الجدولة متوقف.');}
                    $account=self::account($p['accountId']);abort_unless(self::connected($account),409,'أعد ربط الصفحة.');$p['status']=$p['mode']==='schedule'?'scheduled':'queued';$p['authorizedById']=(string)$a->id;$p['authorizationKind']='approval';
                }else{abort_unless($note!=='',422,'اكتب سبب الرفض أو الإعادة.');$p['status']=$action==='return'?'returned':'rejected_final';}}
            $p['history'][]=['at'=>now()->toIso8601String(),'by'=>$a->name,'byId'=>(string)$a->id,'action'=>$action,'note'=>$note];R19Store::put(self::POSTS.$id,$p,$p['_version']);Audit::record($a,'marketing','facebook-post',$id,$action,null,['status'=>$p['status'],'note'=>$note],$tx);return['id'=>$id];
        });$p=self::post($id);SocialAccess::customer($a,$p['customerId']);return self::postPublic($p);
    }
    public function insights(User$a,string$id,int$days): array
    {
        $r=self::account($id);SocialAccess::customer($a,$r['customerId']);abort_unless(self::permission($a,'viewFacebookInsights'),403);abort_unless(self::connected($r),409,'أعد ربط الصفحة.');
        $r['token']=Crypt::decryptString($r['encryptedToken']);return $this->provider->insights($r,$days,FacebookSettings::get());
    }
    public static function mediaUrl(array$p): string
    {
        $c=FacebookSettings::get();abort_unless(FacebookSettings::configured($c),409);$expires=time()+7200;$sign=hash_hmac('sha256','fb|'.$p['id'].'|'.$p['attachmentKey'].'|'.$expires,(string)config('app.key'));
        return$c['publicBaseUrl'].'/api/social/facebook/media/'.rawurlencode($p['id']).'?expires='.$expires.'&signature='.$sign;
    }
    public static function revoke(string$signed,bool$erase=false): array
    {
        $c=FacebookSettings::get();$payload=SocialPolicy::signedRequest($signed,$c['appSecret']);abort_unless($payload,400,'توقيع المزود غير صالح.');$confirmation=bin2hex(random_bytes(24));
        self::lock(function()use($payload,$erase,$confirmation){foreach(R19Store::documents(self::ACCOUNTS)as$a)if(($a['subjectId']??'')===(string)$payload['user_id']){
            unset($a['encryptedToken'],$a['grants']);$a['status']=$erase?'deleted':'revoked';if($erase)unset($a['pageId'],$a['subjectId'],$a['name']);R19Store::put(self::ACCOUNTS.$a['id'],$a,$a['_version']);self::invalidatePosts($a['id'],'أُلغي تفويض Facebook.',$erase);
        }
        foreach(R19Store::documents(self::OAUTH)as$o)if(isset($o['encryptedSelection'])){$d=json_decode(Crypt::decryptString($o['encryptedSelection']),true);if(($d['subjectId']??'')===(string)$payload['user_id']){unset($o['encryptedSelection']);$o['status']='revoked';R19Store::put(self::OAUTH.$o['id'],$o,$o['_version']);}}
        R19Store::put('r34:facebook:deletion:'.$confirmation,['completedAt'=>now()->toIso8601String()],0);});
        return['confirmation_code'=>$confirmation,'url'=>$c['publicBaseUrl'].'/api/social/facebook/deletion-status/'.$confirmation];
    }
}

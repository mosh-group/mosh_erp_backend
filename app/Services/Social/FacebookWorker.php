<?php
namespace App\Services\Social;
use App\Models\{User,StoredFile};
use App\Support\{R19Store,Audit};
use App\Services\Instagram\SocialAccess;
use Illuminate\Support\Facades\{Crypt,Storage};
use Illuminate\Support\Str;
final class FacebookWorker
{
    public function __construct(private FacebookProvider $provider){}
    private static function claim(string$id): ?array
    {
        return FacebookHub::lock(function()use($id){$p=FacebookHub::post($id);
            if(!in_array($p['status'],FacebookHub::WORKABLE,true)||strtotime($p['leaseUntil']??'')>time()||strtotime($p['nextAttemptAt']??'')>time()||($p['status']==='scheduled'&&strtotime($p['scheduledAt']??'')>time()))return null;
            $p['leaseId']=(string)Str::uuid();$p['leaseUntil']=now()->addMinutes(3)->toIso8601String();return R19Store::put(FacebookHub::POSTS.$id,$p,$p['_version']);});
    }
    private static function update(array$p,array$patch,bool$release=true): array
    {
        return FacebookHub::lock(function()use($p,$patch,$release){$current=FacebookHub::post($p['id']);abort_unless(!empty($current['leaseId'])&&hash_equals($current['leaseId'],$p['leaseId']),409,'انتهت ملكية تنفيذ طلب النشر.');
            $next=array_replace($current,$patch);if($release){$next['leaseId']=null;$next['leaseUntil']=null;}
            if($next['status']!==$current['status']){$next['history'][]=['at'=>now()->toIso8601String(),'by'=>'عامل نشر موش','action'=>$next['status']];Audit::record(null,'marketing','facebook-post',$p['id'],'worker-state',['status'=>$current['status']],['status'=>$next['status']]);}
            return R19Store::put(FacebookHub::POSTS.$p['id'],$next,$current['_version']);});
    }
    public static function authorize(array$p): array
    {
        $actor=User::find($p['authorizedById']??'');abort_unless($actor&&$actor->active&&!$actor->must_change,403,'حساب المعتمد غير فعال.');SocialAccess::customer($actor,$p['customerId']);
        $right=$p['authorizationKind']==='approval'?'approveFacebookPosts':($p['mode']==='schedule'?'scheduleFacebookDirect':'publishFacebookDirect');abort_unless(FacebookHub::permission($actor,$right),403,'سُحبت صلاحية النشر/الاعتماد.');
        $a=FacebookHub::account($p['accountId']);abort_unless($a['customerId']===$p['customerId']&&FacebookHub::connected($a),409,'اتصال الصفحة غير متاح.');abort_unless(in_array('pages_manage_posts',$a['grants']??[],true),403,'تفويض الصفحة لا يسمح بالنشر.');$a['token']=Crypt::decryptString($a['encryptedToken']);return$a;
    }
    public function process(string$id): array
    {
        $p=self::claim($id);if(!$p)return FacebookHub::postPublic(FacebookHub::post($id));
        try{$a=self::authorize($p);$config=FacebookSettings::get();abort_unless(FacebookSettings::configured($config),409,'إعداد Facebook غير مكتمل.');
            if(!empty($p['attemptAt'])){
                // A lost POST reply without an object ID has no safe replay path. Never guess by text/time.
                if(empty($p['providerId']))$p=self::update($p,['status'=>'uncertain','lastError'=>'نتيجة محاولة النشر غير مؤكدة. راجع صفحة Facebook؛ لم نكرر الطلب.']);
                else{$r=$this->provider->inspect($a,$p,$config);$state=$r['status'];
                    if($state==='processing'&&strtotime($p['attemptAt'])<time()-86400)$state='uncertain';
                    $patch=['status'=>$state,'lastError'=>$state==='uncertain'?'تحتاج نتيجة معالجة Facebook مراجعة يدوية.':null,'nextAttemptAt'=>now()->addMinute()->toIso8601String()];
                    if($state==='published')$patch['publishedAt']=$p['publishedAt']??now()->toIso8601String();if(!empty($r['permalink']))$patch['permalink']=$r['permalink'];$p=self::update($p,$patch);
                }
            }else{
                $url=null;if($p['mediaKind']!=='text'){$f=StoredFile::where('logical_key',$p['attachmentKey'])->first();abort_unless($f&&Storage::disk('local')->exists($f->path),422,'مرفق المنشور غير متاح.');$url=FacebookHub::mediaUrl($p);}
                // Persist one irreversible intent while holding the same business lock as revocations.
                $p=FacebookHub::lock(function()use($p){$current=FacebookHub::post($p['id']);abort_unless(($current['leaseId']??null)===$p['leaseId']&&empty($current['attemptAt']),409);self::authorize($current);
                    $current['attemptAt']=now()->toIso8601String();$current['status']='publishing';return R19Store::put(FacebookHub::POSTS.$current['id'],$current,$current['_version']);});
                $a=self::authorize($p);$result=$this->provider->publish($a,$p,$url,$config);$patch=$result+['lastError'=>null,'nextAttemptAt'=>now()->addMinute()->toIso8601String()];
                if($result['status']==='published')$patch['publishedAt']=now()->toIso8601String();$p=self::update($p,$patch);
            }
        }catch(\Throwable$e){$attempt=!empty($p['attemptAt']);$knownFailure=$e instanceof FacebookException&&!$e->ambiguous;
            $status=$attempt?($knownFailure&&empty($p['providerId'])?'failed_provider':(!empty($p['providerId'])?'processing':'uncertain')):'blocked';
            if($attempt&&($e instanceof FacebookException&&in_array($e->kind,['auth','permission'],true)))$status='uncertain';
            $msg=$e instanceof FacebookException?$e->getMessage():'تعذر استكمال الطلب. راجع الاتصال والصلاحيات؛ لا تعاود النشر الغامض تلقائياً.';
            try{$p=self::update($p,['status'=>$status,'lastError'=>$msg,'nextAttemptAt'=>now()->addMinute()->toIso8601String()]);}catch(\Throwable$ignored){$p=FacebookHub::post($id);}
        }return FacebookHub::postPublic($p);
    }
    public static function heartbeat(): void {FacebookHub::lock(function(){$old=R19Store::get(FacebookSettings::WORKER);R19Store::put(FacebookSettings::WORKER,['lastRunAt'=>now()->toIso8601String()],(int)($old['_version']??0));});}
    public function tick(int$limit=20): int
    {
        self::heartbeat();$done=0;
        // OAuth candidates expire and their page tokens must not be retained indefinitely.
        FacebookHub::lock(function(){foreach(R19Store::documents(FacebookHub::OAUTH)as$o)if($o['expires']<time()&&!empty($o['encryptedSelection'])){unset($o['encryptedSelection']);$o['status']='expired';R19Store::put(FacebookHub::OAUTH.$o['id'],$o,$o['_version']);}});
        foreach(R19Store::documents(FacebookHub::POSTS)as$p){if($done>=$limit)break;if(!in_array($p['status'],FacebookHub::WORKABLE,true)||strtotime($p['scheduledAt']??'')>time()||strtotime($p['nextAttemptAt']??'')>time())continue;$this->process($p['id']);$done++;self::heartbeat();}
        return$done;
    }
}

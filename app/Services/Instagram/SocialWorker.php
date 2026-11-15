<?php
namespace App\Services\Instagram;
use App\Models\{User, StoredFile};
use App\Support\{R19Store, Audit};
use Illuminate\Support\Facades\{DB, Crypt, Storage};
use Illuminate\Support\Str;

/** Durable, leased outbox stored in the existing database; never in a browser.
 * A lost media_publish response is reconciled, not blindly retried. */
final class SocialWorker
{
    public function __construct(private MetaClient $meta) {}
    private static function lock(callable $work): mixed
    {
        return DB::transaction(function()use($work){
            abort_unless(DB::table('app_locks')->where('id','business')->increment('revision')===1,503);return $work();
        },3);
    }
    public static function heartbeat(): void
    {
        self::lock(function(){ $s=R19Store::get('r28:social:worker');R19Store::put('r28:social:worker',['lastRunAt'=>now()->toIso8601String()],(int)($s['_version']??0)); });
    }
    private static function claim(string $id): ?array
    {
        return self::lock(function()use($id){
            $p=SocialHub::post($id);
            if(!in_array($p['status'],SocialPolicy::WORKABLE,true)||strtotime($p['leaseUntil']??'')>time()||strtotime($p['nextAttemptAt']??'')>time())return null;
            if($p['status']==='scheduled'&&strtotime($p['scheduledAt']??'')>time())return null;
            $p['status']=!empty($p['lastPublishAttemptAt'])?'reconciling':(!empty($p['containerId'])?'processing':'preparing');
            $p['leaseId']=(string)Str::uuid();$p['leaseUntil']=now()->addMinutes(5)->toIso8601String();
            return R19Store::put(SocialHub::POSTS.$id,$p,$p['_version']);
        });
    }
    private static function update(array $claim,array $patch,bool $release=false): array
    {
        return self::lock(function()use($claim,$patch,$release){
            $current=SocialHub::post($claim['id']);
            abort_unless(!empty($current['leaseId'])&&hash_equals($current['leaseId'],$claim['leaseId']),409,'انتهت مهلة تنفيذ عملية النشر.');
            $next=array_replace($current,$patch);if($release){$next['leaseId']=null;$next['leaseUntil']=null;}
            if(($next['status']??'')!==$current['status']){
                $next['history'][]=['at'=>now()->toIso8601String(),'by'=>'عامل نشر MOSH','action'=>$next['status']];
                Audit::record(null,'marketing','instagram-post',$claim['id'],'worker-state',['status'=>$current['status']],['status'=>$next['status']]);
            }
            return R19Store::put(SocialHub::POSTS.$claim['id'],$next,$current['_version']);
        });
    }
    private static function authorize(array $p): array
    {
        $user=User::find($p['authorizedById']??'');abort_unless($user&&$user->active&&!$user->must_change,403,'حساب صاحب صلاحية النشر متوقف.');
        SocialAccess::customer($user,$p['customerId']);
        $right=($p['authorizationKind']??'')==='approval'?'approveInstagramPosts':(($p['mode']??'')==='schedule'?'scheduleInstagramDirect':'publishInstagramDirect');
        abort_unless(SocialAccess::permission($user,$right),403,'أُلغيت صلاحية نشر أو اعتماد هذا المحتوى.');
        $a=SocialHub::account($p['accountId']);abort_unless($a['customerId']===$p['customerId']&&SocialHub::connected($a),409,'أعد ربط الحساب قبل متابعة النشر.');return $a;
    }
    private function refreshAccount(array $a): array
    {
        if(strtotime($a['expiresAt'])>time()+7*86400||strtotime($a['refreshedAt']??$a['connectedAt'])>time()-86400)return $a;
        $result=$this->meta->refresh(SocialHub::token($a));
        if(empty($result['access_token'])||(int)($result['expires_in']??0)<1)throw new MetaException('auth');
        return self::lock(function()use($a,$result){
            $latest=SocialHub::account($a['id']);abort_unless($latest['_version']===$a['_version']&&SocialHub::connected($latest),409,'تغير اتصال الحساب.');
            $latest['encryptedToken']=Crypt::encryptString($result['access_token']);$latest['expiresAt']=now()->addSeconds((int)$result['expires_in'])->toIso8601String();$latest['refreshedAt']=now()->toIso8601String();
            return R19Store::put(SocialHub::ACCOUNTS.$a['id'],$latest,$latest['_version']);
        });
    }
    public function process(string $id): array
    {
        $p=self::claim($id);if(!$p)return SocialPolicy::postPublic(SocialHub::post($id));
        try{
            $a=$this->refreshAccount(self::authorize($p));$token=SocialHub::token($a);$c=SocialSettings::privateConfig();
            if(empty($p['containerId'])){
                $file=StoredFile::where('logical_key',$p['attachmentKey'])->first();abort_unless($file&&Storage::disk('local')->exists($file->path),422,'مرفق المنشور غير موجود.');
                $params=['caption'=>$p['caption']];$url=SocialHub::publicMediaUrl($p);
                if($p['mediaKind']==='reel')$params+=['media_type'=>'REELS','video_url'=>$url,'share_to_feed'=>'true'];else $params['image_url']=$url;
                $result=$this->meta->graph('POST',$a['instagramId'].'/media',$params,$token,$c['graphVersion']);
                $container=(string)($result['id']??'');if(!preg_match('/^\d{1,40}$/D',$container))throw new MetaException('provider');
                $p=self::update($p,['containerId'=>$container,'status'=>'processing','lastError'=>null]);
            }
            $result=$this->meta->graph('GET',$p['containerId'],['fields'=>'status_code'],$token,$c['graphVersion']);$status=$result['status_code']??'';
            if($status==='PUBLISHED')$p=self::update($p,['status'=>'published','publishedAt'=>$p['publishedAt']??now()->toIso8601String(),'lastError'=>empty($p['mediaId'])?'أكد Instagram النشر؛ معرّف المنشور لم يصل في الرد الأول.':null],true);
            elseif(in_array($status,['ERROR','EXPIRED'],true))$p=self::update($p,['status'=>empty($p['lastPublishAttemptAt'])?'failed':'uncertain','lastError'=>'أعاد Instagram حالة '.$status.'. لم تتم إعادة النشر تلقائياً.'],true);
            elseif($status!=='FINISHED')$p=self::update($p,['status'=>empty($p['lastPublishAttemptAt'])?'processing':'reconciling','nextAttemptAt'=>now()->addMinute()->toIso8601String(),'lastError'=>null],true);
            elseif(!empty($p['lastPublishAttemptAt'])){
                $expired=strtotime($p['lastPublishAttemptAt'])<time()-900;
                $p=self::update($p,['status'=>$expired?'uncertain':'reconciling','nextAttemptAt'=>now()->addMinute()->toIso8601String(),
                    'lastError'=>'لم يؤكد Instagram نتيجة محاولة النشر السابقة. لن نكررها لتجنب منشور مكرر.'],true);
            }else{
                // Recheck revocation immediately before the one irreversible operation.
                $a=self::authorize($p);$token=SocialHub::token($a);
                $p=self::update($p,['status'=>'publishing','lastPublishAttemptAt'=>now()->toIso8601String()]);
                $result=$this->meta->graph('POST',$a['instagramId'].'/media_publish',['creation_id'=>$p['containerId']],$token,$c['graphVersion']);
                $mediaId=(string)($result['id']??'');
                if(!preg_match('/^\d{1,40}$/D',$mediaId))throw new MetaException('network');
                $p=self::update($p,['status'=>'published','mediaId'=>$mediaId,'publishedAt'=>now()->toIso8601String(),'lastError'=>null],true);
            }
        }catch(\Throwable $e){
            $message=$e instanceof MetaException?$e->getMessage():'تعذر استكمال النشر. تحقق من الاتصال والصلاحيات والمرفق.';
            $attempted=!empty($p['lastPublishAttemptAt']);
            $uncertain=$attempted && strtotime($p['lastPublishAttemptAt'])<time()-900;
            try{$p=self::update($p,['status'=>$attempted?($uncertain?'uncertain':'reconciling'):($e instanceof MetaException?'failed':'blocked'),
                'lastError'=>$message,'nextAttemptAt'=>now()->addMinute()->toIso8601String()],true);}catch(\Throwable $ignored){$p=SocialHub::post($id);}
        }
        return SocialPolicy::postPublic($p);
    }
    public function tick(int $limit=20): int
    {
        self::heartbeat();$done=0;
        // Refresh expiring, still-valid long-lived tokens even when no posts are due.
        foreach (R19Store::documents(SocialHub::ACCOUNTS) as $account) {
            if (!SocialHub::connected($account)) continue;
            try { $this->refreshAccount($account); }
            catch (\Throwable $e) { /* Never emit credentials or turn a failed refresh into a connected claim. */ }
            self::heartbeat();
        }
        foreach(R19Store::documents(SocialHub::POSTS) as $p){
            if($done>=$limit)break;
            if(!in_array($p['status'],SocialPolicy::WORKABLE,true)||strtotime($p['scheduledAt']??'')>time()||strtotime($p['nextAttemptAt']??'')>time()||strtotime($p['leaseUntil']??'')>time())continue;
            self::heartbeat();$this->process($p['id']);$done++;
        }
        self::heartbeat();return $done;
    }
}

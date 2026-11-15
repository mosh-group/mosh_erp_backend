<?php
namespace App\Services\Instagram;

use App\Models\{User, Setting, StoredFile, FileLink};
use App\Support\{R19Store, Audit};
use Illuminate\Support\Facades\{Crypt, DB, Storage};
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;

final class SocialHub
{
    public const ACCOUNTS = 'r28:social:account:';
    public const POSTS = 'r28:social:post:';
    public function __construct(private MetaClient $meta) {}
    public static function account(string $id): array { $row=R19Store::get(self::ACCOUNTS.$id); abort_unless($row,404); return $row; }
    public static function post(string $id): array { $row=R19Store::get(self::POSTS.$id); abort_unless($row,404); return $row; }
    public static function connected(array $account): bool
    {
        return ($account['status'] ?? '') === 'connected' && !empty($account['encryptedToken']) && strtotime($account['expiresAt'] ?? '') > time();
    }
    public static function token(array $account): string
    {
        abort_unless(self::connected($account),409,'اتصال إنستغرام غير صالح؛ أعد ربط الحساب.');
        return Crypt::decryptString($account['encryptedToken']);
    }
    public function list(User $actor, string $customerId): array
    {
        SocialAccess::customer($actor,$customerId);
        $accounts=[];
        foreach(R19Store::documents(self::ACCOUNTS) as $row) if(($row['customerId']??'')===$customerId) {
            $public=SocialPolicy::accountPublic($row);
            if(($public['status']??'')==='connected'&&!self::connected($row))$public['status']='expired';
            $accounts[]=$public;
        }
        $posts=array_values(array_map([SocialPolicy::class,'postPublic'],array_filter(R19Store::documents(self::POSTS),fn($p)=>($p['customerId']??'')===$customerId)));
        usort($posts,fn($a,$b)=>strcmp($b['createdAt'],$a['createdAt']));
        return ['accounts'=>$accounts,'posts'=>$posts,'capabilities'=>SocialAccess::capabilities($actor),'configuration'=>SocialSettings::publicConfig($actor->root())];
    }
    public function connect(User $actor, string $customerId, string $code, string $requestId): array
    {
        SocialAccess::customer($actor,$customerId,'edit');
        abort_unless(SocialAccess::permission($actor,'connectInstagram'),403);
        $config=SocialSettings::privateConfig(); abort_unless(SocialSettings::configured($config),409,'إعداد تطبيق Instagram غير مكتمل.');
        $profile=$this->meta->exchange($code,$config);
        $result=R19Store::commit($actor,'instagram-connect',['customerId'=>$customerId,'requestId'=>$requestId],function($tx)use($actor,$customerId,$profile){
            SocialAccess::customer($actor,$customerId,'edit');abort_unless(SocialAccess::permission($actor,'connectInstagram'),403);
            $existing=null;
            foreach(R19Store::documents(self::ACCOUNTS) as $row) if(($row['instagramId']??'')===$profile['instagramId']) {
                abort_unless(($row['customerId']??'')===$customerId,409,'هذا الحساب مرتبط بعميل آخر. لم نغيّر الارتباط.');$existing=$row;break;
            }
            $id=$existing['id']??(string)Str::ulid();
            $account=['id'=>$id,'customerId'=>$customerId,'instagramId'=>$profile['instagramId'],'username'=>$profile['username'],
                'accountType'=>$profile['accountType'],'status'=>'connected','encryptedToken'=>Crypt::encryptString($profile['token']),
                'expiresAt'=>now()->addSeconds($profile['expiresIn'])->toIso8601String(),'connectedAt'=>now()->toIso8601String(),
                'connectedById'=>(string)$actor->id,'refreshedAt'=>now()->toIso8601String(),'grantedPermissions'=>$profile['permissions']];
            R19Store::put(self::ACCOUNTS.$id,$account,(int)($existing['_version']??0));
            Audit::record($actor,'marketing','instagram-account',$id,'connect',null,SocialPolicy::accountPublic($account),$tx);
            return ['accountId'=>$id];
        });
        SocialAccess::customer($actor,$customerId);return SocialPolicy::accountPublic(self::account($result['accountId']));
    }
    public function disconnect(User $actor,string $id,array $input): array
    {
        $result=R19Store::commit($actor,'instagram-disconnect:'.$id,$input,function($tx)use($actor,$id,$input){
            $a=self::account($id);SocialAccess::customer($actor,$a['customerId'],'edit');
            abort_unless(SocialAccess::permission($actor,'connectInstagram'),403);
            abort_unless((int)$input['_version']===$a['_version'],409,'تغيّر الحساب؛ حدّث الصفحة.');
            $a['status']='disconnected';unset($a['encryptedToken'],$a['grantedPermissions']);
            R19Store::put(self::ACCOUNTS.$id,$a,$a['_version']);
            Audit::record($actor,'marketing','instagram-account',$id,'disconnect',null,['status'=>'disconnected'],$tx);
            return ['accountId'=>$id];
        });
        $a=self::account($id);SocialAccess::customer($actor,$a['customerId']);return SocialPolicy::accountPublic($a);
    }
    private static function file(User $actor,array $input,?array $old): ?StoredFile
    {
        $key=$input['attachmentKey']??'';
        if($key==='')return null;
        $file=StoredFile::where('logical_key',$key)->firstOrFail();
        abort_unless($actor->root()||(string)$file->owner_id===(string)$actor->id||($old&&($old['attachmentKey']??'')===$key),403,'هذا المرفق غير متاح لك.');
        abort_unless(Storage::disk('local')->exists($file->path),422,'ملف المرفق غير موجود.');
        if(($input['mediaKind']??'image')==='image') {
            abort_unless($file->mime==='image/jpeg'&&(int)$file->size<=8*1024*1024,422,'الصورة المطلوبة JPEG بحجم لا يتجاوز 8 ميغابايت.');
            $info=@getimagesize(Storage::disk('local')->path($file->path));
            abort_unless($info&&$info[1]>0&&$info[0]/$info[1]>=0.8&&$info[0]/$info[1]<=1.91,422,'نسبة أبعاد الصورة يجب أن تكون من 4:5 حتى 1.91:1.');
        }else abort_unless($file->mime==='video/mp4'&&(int)$file->size<=25*1024*1024,422,'الريلز في هذه النسخة ملف MP4 حتى 25 ميغابايت.');
        return $file;
    }
    public function savePost(User $actor,array $input,?string $id=null): array
    {
        $result=R19Store::commit($actor,'instagram-post:'.($id??'new'),$input,function($tx)use($actor,$input,$id){
            $old=$id?self::post($id):null;$customerId=$old['customerId']??$input['customerId'];
            SocialAccess::customer($actor,$customerId,'create');
            if($old){abort_unless(SocialPolicy::canEdit($old['status']),409,'لا يمكن تعديل محتوى دخل مسار النشر؛ ألغِ الجدولة أولاً.');abort_unless((int)$input['_version']===$old['_version'],409,'تغيّر المنشور.');}
            $mode=$input['mode'];$accountId=(string)($input['accountId']??'');$account=$accountId!==''?self::account($accountId):null;
            if($account)abort_unless($account['customerId']===$customerId,422,'الحساب يتبع عميلاً آخر.');
            $file=self::file($actor,$input,$old);
            if($mode!=='draft') {
                abort_unless($account&&self::connected($account),422,'اربط حساب Instagram صالحاً قبل طلب النشر.');
                abort_unless($file,422,'إنستغرام يحتاج صورة أو فيديو؛ لا يمكن نشر نص منفرد.');
                abort_unless(SocialSettings::configured(SocialSettings::privateConfig()),409,'إعداد الربط غير مكتمل.');
            }
            $when=null;
            if($mode==='schedule') {
                abort_unless(!empty($input['scheduledAt'])&&preg_match('/(Z|[+-]\d{2}:\d{2})$/D',$input['scheduledAt']),422,'موعد النشر يجب أن يتضمن المنطقة الزمنية.');
                $at=Carbon::parse($input['scheduledAt']);abort_unless($at->isFuture(),422,'اختر موعداً مستقبلياً.');
                abort_unless(SocialSettings::scheduler()['active'],409,'عامل الجدولة غير مشغّل على السيرفر. شغّله قبل اعتماد جدولة.');
                $when=$at->utc()->toIso8601String();
            }
            $direct=SocialAccess::permission($actor,$mode==='schedule'?'scheduleInstagramDirect':'publishInstagramDirect');
            $row=['id'=>$id??(string)Str::ulid(),'customerId'=>$customerId,'accountId'=>$accountId,'caption'=>$input['caption']??'',
                'mediaKind'=>$input['mediaKind'],'attachmentKey'=>$file?->logical_key??'','attachmentName'=>$file?->name??'',
                'mode'=>$mode,'scheduledAt'=>$when,'status'=>SocialPolicy::requestedStatus($mode,$direct),
                'createdAt'=>$old['createdAt']??now()->toIso8601String(),'createdBy'=>$old['createdBy']??$actor->name,
                'createdById'=>$old['createdById']??(string)$actor->id,'submittedById'=>(string)$actor->id,
                'authorizedById'=>$direct&&$mode!=='draft'?(string)$actor->id:null,'authorizationKind'=>$direct?'direct':null,
                'lastError'=>null,'history'=>$old['history']??[]];
            $row['history'][]=['at'=>now()->toIso8601String(),'by'=>$actor->name,'action'=>$row['status']];
            R19Store::put(self::POSTS.$row['id'],$row,(int)($old['_version']??0));
            // Explicit protected link: generic upload cleanup cannot remove live/scheduled media.
            FileLink::where(['resource'=>'r28-social-post','record_id'=>$row['id']])->delete();
            if($file)FileLink::firstOrCreate(['file_id'=>$file->id,'resource'=>'r28-social-post','record_id'=>$row['id']]);
            Audit::record($actor,'marketing','instagram-post',$row['id'],'save',$old?SocialPolicy::postPublic($old):null,SocialPolicy::postPublic($row),$tx);
            return ['postId'=>$row['id']];
        });
        $post=self::post($result['postId']);SocialAccess::customer($actor,$post['customerId']);
        return SocialPolicy::postPublic($post);
    }
    public function decide(User $actor,string $id,array $input): array
    {
        $result=R19Store::commit($actor,'instagram-decision:'.$id,$input,function($tx)use($actor,$id,$input){
            $p=self::post($id);SocialAccess::customer($actor,$p['customerId']);abort_unless((int)$input['_version']===$p['_version'],409,'تغيّر المنشور.');
            $old=SocialPolicy::postPublic($p);
            if($input['action']==='cancel'){
                abort_unless((string)$p['createdById']===(string)$actor->id||SocialAccess::permission($actor,'approveInstagramPosts'),403);
                abort_unless(SocialPolicy::canCancel($p['status']),409,'بدأ إرسال المحتوى أو حالته غير مؤكدة؛ لا يمكن اعتباره ملغى.');$p['status']='cancelled';
            }else{
                abort_unless(SocialAccess::permission($actor,'approveInstagramPosts'),403,'اعتماد المنشور يحتاج صلاحية مستقلة.');
                abort_unless($p['status']==='pending_approval',409,'المنشور ليس بانتظار الاعتماد.');
                abort_unless($actor->root()||(string)$p['submittedById']!==(string)$actor->id,403,'لا يجوز اعتماد الطلب الذي أرسلته بنفسك دون صلاحية النشر المباشر.');
                if(in_array($input['action'],['reject','return'],true)){$p['status']=$input['action']==='reject'?'rejected_final':'rejected';$p['lastError']=$input['note'];}
                else{
                    abort_unless(self::connected(self::account($p['accountId'])),409,'الاتصال بالحساب منتهي.');
                    if($p['mode']==='schedule'){
                        abort_unless(strtotime($p['scheduledAt'])>time(),409,'موعد الجدولة مضى أثناء انتظار الاعتماد. أعد المنشور لتحديد موعد جديد.');
                        abort_unless(SocialSettings::scheduler()['active'],409,'عامل الجدولة غير مشغّل.');
                    }
                    $p['status']=$p['mode']==='schedule'?'scheduled':'queued';$p['approvedAt']=now()->toIso8601String();$p['approvedBy']=$actor->name;
                    $p['authorizedById']=(string)$actor->id;$p['authorizationKind']='approval';
                }
            }
            $p['history'][]=['at'=>now()->toIso8601String(),'by'=>$actor->name,'action'=>$input['action']==='reject'?'reject_final':$input['action'],'note'=>$input['note']??''];
            R19Store::put(self::POSTS.$id,$p,$p['_version']);Audit::record($actor,'marketing','instagram-post',$id,$input['action'],$old,SocialPolicy::postPublic($p),$tx);return ['postId'=>$id];
        });
        $p=self::post($result['postId']);SocialAccess::customer($actor,$p['customerId']);return SocialPolicy::postPublic($p);
    }
    public function insights(User $actor,string $accountId,int $days=7,?string $postId=null): array
    {
        $a=self::account($accountId);SocialAccess::customer($actor,$a['customerId']);
        abort_unless(SocialAccess::permission($actor,'viewInstagramInsights'),403,'لا تملك صلاحية إحصائيات إنستغرام.');
        $token=self::token($a);$config=SocialSettings::privateConfig();$node=$a['instagramId'];$since=now()->subDays($days)->utc()->timestamp;$until=now()->utc()->timestamp;
        if($postId){$p=self::post($postId);abort_unless($p['accountId']===$accountId&&!empty($p['mediaId'])&&$p['status']==='published',422,'معرّف المنشور المنشور غير متوفر.');$node=$p['mediaId'];}
        $out=['fetchedAt'=>now()->toIso8601String(),'since'=>gmdate('c',$since),'until'=>gmdate('c',$until),'metrics'=>[],'unavailable'=>[]];
        foreach($postId?['views','reach','saved','total_interactions']:['reach','views','total_interactions'] as $metric){
            $params=['metric'=>$metric];if(!$postId)$params+=['period'=>'day','metric_type'=>'total_value','since'=>$since,'until'=>$until];
            try{$data=$this->meta->graph('GET',$node.'/insights',$params,$token,$config['graphVersion']);
                $entry=$data['data'][0]??null;$value=$entry['total_value']['value']??null;
                if($value===null&&isset($entry['values'])){ $values=array_column($entry['values'],'value');if($values&&count(array_filter($values,'is_numeric'))===count($values))$value=array_sum($values); }
                if(!is_numeric($value))$out['unavailable'][$metric]='لا تتوفر بيانات لهذه الفترة.';else $out['metrics'][$metric]=0+$value;
            }catch(MetaException $e){if($e->kind==='auth')throw $e;$out['unavailable'][$metric]=$e->getMessage();}
        }
        return $out;
    }
    public static function publicMediaUrl(array $post): string
    {
        $base=SocialSettings::privateConfig()['publicBaseUrl'];abort_unless(SocialPolicy::httpsUrl($base),409,'رابط الوسائط HTTPS غير مجهز.');
        $expires=time()+7200;$signature=hash_hmac('sha256',$post['id'].'|'.$post['attachmentKey'].'|'.$expires,(string)config('app.key'));
        return $base.'/api/social/media/'.rawurlencode($post['id']).'?expires='.$expires.'&signature='.$signature;
    }
    public static function providerRevocation(string $signed,bool $erase=false): array
    {
        $c=SocialSettings::privateConfig();$data=SocialPolicy::signedRequest($signed,$c['appSecret']);abort_unless($data,400,'توقيع المزود غير صالح.');
        $id=(string)$data['user_id'];$confirmation=(string)Str::ulid();
        DB::transaction(function()use($id,$erase,$confirmation){
            DB::table('app_locks')->where('id','business')->increment('revision');
            foreach(R19Store::documents(self::ACCOUNTS) as $a){if(($a['instagramId']??'')!==$id)continue;
                unset($a['encryptedToken'],$a['grantedPermissions']);$a['status']='revoked';
                if($erase){unset($a['instagramId'],$a['username'],$a['accountType']);$a['status']='deleted';}
                R19Store::put(self::ACCOUNTS.$a['id'],$a,$a['_version']);
                foreach(R19Store::documents(self::POSTS) as $p){if(($p['accountId']??'')!==$a['id'])continue;
                    // Erase platform-sourced metadata, not company-owned source captions or attachments.
                    if($erase)unset($p['mediaId'],$p['permalink'],$p['containerId']);
                    // A worker with an in-flight provider call must not reintroduce revoked metadata.
                    $p['leaseId']=null;$p['leaseUntil']=null;
                    if($p['status']!=='published')$p['status']=!empty($p['lastPublishAttemptAt'])?'uncertain':'blocked';$p['lastError']='أُلغي تفويض الحساب من Instagram.';
                    R19Store::put(self::POSTS.$p['id'],$p,$p['_version']);
                }
            }
            R19Store::put('r28:social:deletion:'.$confirmation,['confirmationCode'=>$confirmation,'completedAt'=>now()->toIso8601String()],0);
        },3);
        return ['confirmation_code'=>$confirmation,'url'=>$c['publicBaseUrl'].'/api/social/instagram/deletion-status/'.$confirmation];
    }
}

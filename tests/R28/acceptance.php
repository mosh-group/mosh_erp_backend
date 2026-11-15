<?php
/** R28 isolated tests. Runs without Laravel boot, network, files, or a database.
 * Tests production policy/manager/outbox code with explicitly simulated persistence,
 * clock, identity, provider and storage boundaries. Not an HTTP/SQL/Meta integration test.
 * Run: php tests/R28/acceptance.php
 */
namespace QA {
    class HttpError extends \RuntimeException { public function __construct(public int $status,string $message=''){parent::__construct($message);} }
    class Clock extends \DateTimeImmutable {
        public function toIso8601String():string{return $this->format('c');}
        public function addMinutes(int $n):static{return $this->modify("+$n minutes");}
        public function addMinute():static{return $this->modify('+1 minute');}
        public function addSeconds(int $n):static{return $this->modify("+$n seconds");}
        public function subDays(int $n):static{return $this->modify("-$n days");}
        public function utc():static{return $this->setTimezone(new \DateTimeZone('UTC'));}
        public function isFuture():bool{return $this->getTimestamp()>time();}
        public function __get(string $key):mixed{return $key==='timestamp'?$this->getTimestamp():null;}
    }
    class DB { public function where(...$args):static{return $this;} public function increment(...$args):int{return 1;} }
    class Disk { public static bool $exists=true;public function exists($path):bool{return self::$exists;}public function path($p):string{return $p;} }
    class Query {public function __construct(private mixed $item){} public function first():mixed{return $this->item;}public function firstOrFail():mixed{return $this->item ?? throw new HttpError(404);} }
    function check(string $name,bool $condition):void { $GLOBALS['checks'][]=['name'=>$name,'passed'=>$condition]; }
    function throws(string $name,callable $work,string $class=\Throwable::class,?int $status=null):void{
        try{$work();check($name,false);}catch(\Throwable $e){check($name,$e instanceof $class&&($status===null||($e->status??null)===$status));}
    }
}
namespace { function abort_unless($condition,$status,$message=''):void{if(!$condition)throw new \QA\HttpError($status,$message);} function abort_if($condition,$status,$message=''):void{abort_unless(!$condition,$status,$message);} function abort($status,$message=''):never{throw new \QA\HttpError($status,$message);} function now():\QA\Clock{return new \QA\Clock('now',new \DateTimeZone('UTC'));} function config($key,$default=null):mixed{return $key==='app.key'?'TEST_ONLY_KEY':$default;} }
namespace Illuminate\Support {
    class Str {private static int $n=0;public static function uuid():string{return 'TEST-'.++self::$n;}public static function ulid():string{return 'TEST'.++self::$n;} }
    class Carbon extends \QA\Clock {public static function parse(string $v):static{return new static($v);} }
}
namespace Illuminate\Support\Facades {
    class DB {public static function transaction(callable $fn,$attempts=1):mixed{return $fn();}public static function table($name):\QA\DB{return new \QA\DB();} }
    class Crypt {public static function encryptString($text):string{return 'SIMULATED:'.$text;}public static function decryptString($text):string{return substr($text,10);} }
    class Storage {public static function disk($name):\QA\Disk{return new \QA\Disk();} }
}
namespace App\Models {
    class User {
        public static array $rows=[];public bool $active=true,$must_change=false,$isRoot=false;
        public array $permissions=['publishInstagramDirect'=>true,'approveInstagramPosts'=>true,'connectInstagram'=>true,'viewInstagramInsights'=>true];
        public string $name='Test publisher'; public function __construct(public string $id='U1'){}
        public static function find($id):?self{return self::$rows[$id]??null;} public function root():bool{return $this->isRoot;}
        public function unsetRelation($name):static{return $this;}public function refresh():static{return $this;}
        public function allows($module,$action='view'):bool{return $this->active;}
        public function globalPermission($key):bool{return $this->isRoot||($this->permissions[$key]??false);}
    }
    class Customer {public array $data=[];public function document():array{return ['id'=>'C1'];}public static function findOrFail($id):self{return new self();} }
    class StoredFile {public string $path='SIMULATED_FILE',$logical_key='F1',$name='test.jpg',$mime='image/jpeg',$owner_id='U1',$id='F1';public int $size=1024;public static function where(...$args):\QA\Query{return new \QA\Query(new self());} }
    class FileLink {public static function where($args):self{return new self();}public function delete():void{}public static function firstOrCreate($args):self{return new self();} }
}
namespace App\Support {
    class R19Store {
        public static array $rows=[],$requests=[];
        public static function get(string $key):?array{return self::$rows[$key]??null;}
        public static function put(string $key,array $value,int $version):array{
            \abort_unless((self::$rows[$key]['_version']??0)===$version,409,'Simulated CAS conflict');
            return self::$rows[$key]=array_replace($value,['_version'=>$version+1]);
        }
        public static function documents(string $prefix):array{return array_values(array_filter(self::$rows,fn($v,$k)=>str_starts_with($k,$prefix),ARRAY_FILTER_USE_BOTH));}
        public static function commit($actor,string $scope,array $input,callable $fn):array {
            \abort_unless($actor->active&&!$actor->must_change,403);
            $key=$actor->id.':'.$input['requestId'];$hash=hash('sha256',json_encode([$scope,$input]));
            if(isset(self::$requests[$key])){\abort_unless(self::$requests[$key]['hash']===$hash,409);return self::$requests[$key]['result'];}
            $result=$fn('SIMULATED_TX');self::$requests[$key]=['hash'=>$hash,'result'=>$result];return $result;
        }
    }
    class Audit {public static array $rows=[];public static function record(...$args):void{self::$rows[]=$args;} }
    class RecordAccess {public static bool $allowed=true;public static function readable(...$args):bool{return self::$allowed;} }
}
namespace App\Services\Instagram {
    class SocialSettings {
        public static bool $worker=true;
        public static function privateConfig():array{return ['publicBaseUrl'=>'https://qa.invalid','graphVersion'=>'v26.0','appId'=>'123','appSecret'=>'TEST_SECRET'];}
        public static function configured(array $c):bool{return true;}
        public static function scheduler():array{return ['active'=>self::$worker];}
    }
}
namespace {
    $root=dirname(__DIR__,2);$GLOBALS['checks']=[];
    require $root.'/app/Support/DepartmentManagers.php';
    foreach(['MetaException','MetaClient','SocialPolicy','SocialAccess','SocialHub','SocialWorker'] as $file)require $root.'/app/Services/Instagram/'.$file.'.php';
}
namespace QA {
    use App\Support\{DepartmentManagers,R19Store,RecordAccess};
    use App\Services\Instagram\{SocialPolicy,SocialHub,SocialWorker,MetaClient,MetaException,SocialSettings};
    use App\Models\User;
    final class Provider extends MetaClient {
        public array $calls=[];public array $script=[];public $onCall=null;
        public function graph(string $method,string $path,array $data,string $token,string $version):array {
            $this->calls[]=[$method,$path,$data];if($this->onCall)($this->onCall)($method,$path);
            if(!$this->script)throw new \LogicException('Unexpected provider call');
            $next=array_shift($this->script);if($next instanceof \Throwable)throw $next;return $next;
        }
        public function refresh(string $token):array {$this->calls[]=['REFRESH'];return ['access_token'=>'renewed','expires_in'=>60*86400];}
        public function publishes():int{return count(array_filter($this->calls,fn($c)=>str_ends_with($c[1]??'','/media_publish')));}
    }
    function seed(array $patch=[]):array {
        R19Store::$rows=[];R19Store::$requests=[];RecordAccess::$allowed=true;Disk::$exists=true;SocialSettings::$worker=true;
        User::$rows=['U1'=>new User('U1'),'U2'=>new User('U2')];
        $a=['id'=>'A1','customerId'=>'C1','instagramId'=>'123','username'=>'qa_only','status'=>'connected','encryptedToken'=>'SIMULATED:testtoken','expiresAt'=>gmdate('c',time()+30*86400),'connectedAt'=>gmdate('c',time()-86400*10),'_version'=>1];
        R19Store::$rows[SocialHub::ACCOUNTS.'A1']=$a;
        $p=array_replace(['id'=>'P1','customerId'=>'C1','accountId'=>'A1','caption'=>'test','mediaKind'=>'image','attachmentKey'=>'F1','status'=>'queued','authorizedById'=>'U1','authorizationKind'=>'direct','mode'=>'publish','createdById'=>'U2','submittedById'=>'U2','createdBy'=>'Editor','createdAt'=>gmdate('c'),'history'=>[],'_version'=>1],$patch);
        R19Store::$rows[SocialHub::POSTS.'P1']=$p;return $p;
    }
    function post(array $patch):void{R19Store::$rows[SocialHub::POSTS.'P1']=array_replace(R19Store::$rows[SocialHub::POSTS.'P1'],$patch);}
    $map=['التصوير'=>'E1','المونتاج'=>'E1','التصميم'=>'E2'];
    $new=DepartmentManagers::replace($map,'التسويق','E1','');
    check('Assign fourth department preserves all existing assignments',count($new)===4&&$new['المونتاج']==='E1'&&$new['التصوير']==='E1'&&$new['التصميم']==='E2');
    check('Manager replacement is not an in-place mutation',$map===['التصوير'=>'E1','المونتاج'=>'E1','التصميم'=>'E2']);
    check('Remove one department keeps other assignments',DepartmentManagers::replace($map,'المونتاج','','E1')===['التصوير'=>'E1','التصميم'=>'E2']);
    throws('Same-department stale write is rejected',fn()=>DepartmentManagers::replace($map,'المونتاج','E3','E2'),\DomainException::class);
    $first=DepartmentManagers::replace($map,'المونتاج','E3','E1');$second=DepartmentManagers::replace($first,'التصوير','E4','E1');
    check('Changes in different departments merge without losing either',$second['المونتاج']==='E3'&&$second['التصوير']==='E4');
    check('Legacy empty array normalizes without inventing managers',DepartmentManagers::normalize([])===[]);
    check('Object maps and numeric IDs normalize safely',DepartmentManagers::normalize((object)['D'=>123,'E'=>null,'F'=>'','G'=>['X']])===['D'=>'123']);
    foreach(['draft','publish','schedule'] as $mode)foreach([true,false] as $direct){$expected=$mode==='draft'?'draft':($direct?($mode==='schedule'?'scheduled':'queued'):'pending_approval');check("$mode status respects direct permission ".($direct?'granted':'denied'),SocialPolicy::requestedStatus($mode,$direct)===$expected);}
    throws('Unknown publishing mode rejected',fn()=>SocialPolicy::requestedStatus('fake',true),\DomainException::class);
    foreach(['publishing','reconciling','uncertain','published'] as $s){check("$s cannot be edited or cancelled",!SocialPolicy::canEdit($s)&&!SocialPolicy::canCancel($s));}
    check('Scheduled content cannot be edited before explicit cancellation',!SocialPolicy::canEdit('scheduled')&&SocialPolicy::canCancel('scheduled'));
    foreach(['http://qa.invalid','https://user:pass@qa.invalid','https://qa.invalid?secret=x','https://qa.invalid#x','javascript:alert(1)'] as $url)check('Reject unsafe media/config URL '.$url,!SocialPolicy::httpsUrl($url));
    check('HTTPS base URL accepted',SocialPolicy::httpsUrl('https://qa.invalid'));
    check('Public account excludes token and grants',SocialPolicy::accountPublic(['id'=>'A','encryptedToken'=>'secret','grantedPermissions'=>['x']])===['id'=>'A']);
    check('Public post excludes lease/provider credentials',SocialPolicy::postPublic(['id'=>'P','leaseId'=>'private','authorizedById'=>'U1','containerId'=>'123','encryptedToken'=>'secret'])===['id'=>'P']);
    $b64=fn($v)=>rtrim(strtr(base64_encode($v),'+/','-_'),'=');$payload=$b64(json_encode(['algorithm'=>'HMAC-SHA256','user_id'=>'123']));$signed=$b64(hash_hmac('sha256',$payload,'TEST_SECRET',true)).'.'.$payload;
    check('Valid provider signature accepted',(SocialPolicy::signedRequest($signed,'TEST_SECRET')['user_id']??'')==='123');
    check('Wrong signing key rejected',SocialPolicy::signedRequest($signed,'WRONG')===null);
    check('Tampered callback rejected',SocialPolicy::signedRequest($signed.'x','TEST_SECRET')===null);
    check('Oversized callback rejected',SocialPolicy::signedRequest(str_repeat('a',10001),'TEST_SECRET')===null);
    $meta=new MetaClient();throws('Provider rejects non-approved host',fn()=>$meta->call('GET','https://attacker.invalid'),\LogicException::class);
    throws('Provider rejects injected graph paths',fn()=>$meta->graph('GET','123/../../oauth',[],'x','v26.0'),\LogicException::class);
    throws('Provider rejects malformed API version',fn()=>$meta->graph('GET','me',[],'x','latest/evil'),\LogicException::class);
    seed();$m=new Provider();$m->script=[['id'=>'456'],['status_code'=>'FINISHED'],['id'=>'789']];$w=new SocialWorker($m);$r=$w->process('P1');
    check('Provider-confirmed image is published with actual media ID',$r['status']==='published'&&$r['mediaId']==='789');
    check('One publication call for success',$m->publishes()===1);$w->process('P1');check('Repeated processing never republishes confirmed content',$m->publishes()===1);
    check('Container and publishing intent persisted',!empty(SocialHub::post('P1')['containerId'])&&!empty(SocialHub::post('P1')['lastPublishAttemptAt']));
    seed(['mediaKind'=>'reel']);$m=new Provider();$m->script=[['id'=>'456'],['status_code'=>'IN_PROGRESS']];$r=(new SocialWorker($m))->process('P1');
    check('Reels wait for provider processing',$r['status']==='processing'&&$m->publishes()===0&&($m->calls[0][2]['media_type']??'')==='REELS');
    seed();$m=new Provider();$m->script=[['id'=>'456'],['status_code'=>'FINISHED'],new MetaException('network')];$w=new SocialWorker($m);$r=$w->process('P1');
    check('Lost publish response is reconciled, not marked successful',$r['status']==='reconciling'&&!isset($r['mediaId']));
    post(['nextAttemptAt'=>null]);$m->script=[['status_code'=>'FINISHED']];$w->process('P1');check('FINISHED after lost response does not repeat media_publish',$m->publishes()===1);
    post(['nextAttemptAt'=>null,'lastPublishAttemptAt'=>gmdate('c',time()-1000)]);$m->script=[['status_code'=>'FINISHED']];$r=$w->process('P1');check('Long ambiguous result becomes uncertain without automatic retry',$r['status']==='uncertain'&&$m->publishes()===1);
    seed(['containerId'=>'456','lastPublishAttemptAt'=>gmdate('c',time()-1000),'status'=>'reconciling']);User::$rows['U1']->active=false;$m=new Provider();$r=(new SocialWorker($m))->process('P1');check('Lost authorization after ambiguous publish becomes uncertain instead of waiting forever',$r['status']==='uncertain'&&$m->calls===[]);
    seed(['containerId'=>'456','lastPublishAttemptAt'=>gmdate('c',time()-120),'status'=>'reconciling']);$m=new Provider();$m->script=[['status_code'=>'PUBLISHED']];$r=(new SocialWorker($m))->process('P1');check('Provider PUBLISHED confirms a lost response without another POST',$r['status']==='published'&&$m->publishes()===0);
    foreach(['draft','pending_approval','published','uncertain','cancelled'] as $s){seed(['status'=>$s]);$m=new Provider();(new SocialWorker($m))->process('P1');check("Worker never submits $s",$m->calls===[]);}
    seed(['status'=>'scheduled','scheduledAt'=>gmdate('c',time()+3600)]);$m=new Provider();$w=new SocialWorker($m);$w->process('P1');check('Future schedule does not publish early',$m->calls===[]);
    seed(['leaseId'=>'OTHER_WORKER','leaseUntil'=>gmdate('c',time()+100)]);$m=new Provider();(new SocialWorker($m))->process('P1');check('Active lease blocks another execution',$m->calls===[]);
    seed();User::$rows['U1']->permissions['publishInstagramDirect']=false;$m=new Provider();$r=(new SocialWorker($m))->process('P1');check('Revoked direct permission blocks publishing',$r['status']==='blocked'&&$m->publishes()===0);
    seed(['authorizationKind'=>'approval']);User::$rows['U1']->permissions['publishInstagramDirect']=false;$m=new Provider();$m->script=[['id'=>'456'],['status_code'=>'FINISHED'],['id'=>'789']];$r=(new SocialWorker($m))->process('P1');check('Approval permission is independent of direct publishing',$r['status']==='published');
    seed();User::$rows['U1']->active=false;$m=new Provider();$r=(new SocialWorker($m))->process('P1');check('Inactive authorizer cannot publish',$r['status']==='blocked'&&$m->calls===[]);
    seed();RecordAccess::$allowed=false;$m=new Provider();$r=(new SocialWorker($m))->process('P1');check('Lost customer access blocks publishing',$r['status']==='blocked'&&$m->calls===[]);
    seed();R19Store::$rows[SocialHub::ACCOUNTS.'A1']['customerId']='C2';$m=new Provider();$r=(new SocialWorker($m))->process('P1');check('Cross-customer account rejected by worker',$r['status']==='blocked'&&$m->calls===[]);
    seed();$m=new Provider();$m->onCall=function($method,$path){if($method==='GET')User::$rows['U1']->permissions['publishInstagramDirect']=false;};$m->script=[['id'=>'456'],['status_code'=>'FINISHED']];$r=(new SocialWorker($m))->process('P1');check('Permission rechecked immediately before irreversible publish',$r['status']==='blocked'&&$m->publishes()===0);
    seed();$m=new Provider();$m->onCall=function($method,$path){if(str_ends_with($path,'/media_publish'))post(['leaseId'=>null,'leaseUntil'=>null,'status'=>'uncertain']);};$m->script=[['id'=>'456'],['status_code'=>'FINISHED'],['id'=>'789']];$r=(new SocialWorker($m))->process('P1');check('Invalidated lease prevents a late worker from overwriting revocation',$r['status']==='uncertain'&&!isset($r['mediaId']));
    seed(['status'=>'draft']);R19Store::$rows[SocialHub::ACCOUNTS.'A1']['expiresAt']=gmdate('c',time()+2*86400);$m=new Provider();(new SocialWorker($m))->tick();check('Idle worker refreshes expiring accounts with no due posts',($m->calls[0][0]??'')==='REFRESH'&&SocialHub::account('A1')['encryptedToken']==='SIMULATED:renewed');
    seed(['status'=>'pending_approval']);$hub=new SocialHub(new Provider());$a=User::$rows['U1'];$r=$hub->decide($a,'P1',['action'=>'approve','_version'=>1,'requestId'=>'approve-test']);check('Independent reviewer advances to queued',$r['status']==='queued');
    $again=$hub->decide($a,'P1',['action'=>'approve','_version'=>1,'requestId'=>'approve-test']);check('Idempotent approval has one decision history row',count($again['history'])===1);
    seed(['status'=>'pending_approval','submittedById'=>'U1']);$hub=new SocialHub(new Provider());throws('Reviewer cannot approve own submission',fn()=>$hub->decide(User::$rows['U1'],'P1',['action'=>'approve','_version'=>1,'requestId'=>'selfapprove-test']),HttpError::class,403);
    seed(['status'=>'pending_approval','mode'=>'schedule','scheduledAt'=>gmdate('c',time()-120)]);$hub=new SocialHub(new Provider());throws('Expired schedule must be returned for a new time',fn()=>$hub->decide(User::$rows['U1'],'P1',['action'=>'approve','_version'=>1,'requestId'=>'oldtime-test']),HttpError::class,409);
    seed(['status'=>'pending_approval','mode'=>'schedule','scheduledAt'=>gmdate('c',time()+3600)]);SocialSettings::$worker=false;$hub=new SocialHub(new Provider());throws('Approval cannot schedule when worker inactive',fn()=>$hub->decide(User::$rows['U1'],'P1',['action'=>'approve','_version'=>1,'requestId'=>'noworker-test']),HttpError::class,409);
    seed(['status'=>'publishing','lastPublishAttemptAt'=>gmdate('c')]);$hub=new SocialHub(new Provider());throws('Cannot cancel after publishing begins',fn()=>$hub->decide(User::$rows['U1'],'P1',['action'=>'cancel','_version'=>1,'requestId'=>'cancel-test']),HttpError::class,409);
    seed(['status'=>'reconciling','containerId'=>'456','lastPublishAttemptAt'=>gmdate('c'),'leaseId'=>'RUNNING']);SocialHub::providerRevocation($signed,true);$p=SocialHub::post('P1');$a=SocialHub::account('A1');check('Signed deletion removes token and provider identity',!array_intersect(['encryptedToken','instagramId','username'],array_keys($a)));check('Deletion fences in-flight worker and preserves uncertain result',$p['status']==='uncertain'&&$p['leaseId']===null&&!isset($p['containerId']));check('Deletion preserves company-owned source content',$p['caption']==='test'&&$p['attachmentKey']==='F1');
    $checks=$GLOBALS['checks'];$passed=count(array_filter($checks,fn($c)=>$c['passed']));echo json_encode(['passed'=>$passed,'total'=>count($checks),'checks'=>$checks,'boundary'=>'Production policy/manager/outbox/approval code with simulated persistence, provider, identity, clock and file boundaries; no SQL, HTTP, encryption implementation or live Meta validation.'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;exit($passed===count($checks)?0:1);
}

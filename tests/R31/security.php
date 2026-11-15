<?php
/** R31 real authorization/service methods and Laravel encryption primitive.
 * Identity/ORM/store/HTTP facades are explicit doubles. No Laravel app boot. */
namespace QA {
 class HttpError extends \RuntimeException {function __construct(public int $status,string $m=''){parent::__construct($m);}}
 class Query {function __construct(public string $key=''){}function where(...$a){return $this;}function increment($k){return 1;}function exists(){return isset(\App\Support\R19Store::$rows[$this->key]);}}
 function ck($n,$v){$GLOBALS['checks'][]=['name'=>$n,'passed'=>(bool)$v];}
 function denied($n,$fn,$code=403){try{$fn();ck($n,false);}catch(\Throwable $e){ck($n,$e instanceof HttpError&&$e->status===$code);}}
}
namespace {function data_get($a,$p,$d=null){foreach(explode('.',$p)as$k){if(is_array($a)&&array_key_exists($k,$a))$a=$a[$k];elseif(is_object($a)&&isset($a->$k))$a=$a->$k;else return $d;}return $a;}function abort($c,$m=''){throw new \QA\HttpError($c,$m);}function abort_unless($v,$c,$m=''){if(!$v)abort($c,$m);}function abort_if($v,$c,$m=''){if($v)abort($c,$m);}}
namespace Illuminate\Foundation\Auth {#[\AllowDynamicProperties] class User {function refresh(){if(isset($this->onRefresh))($this->onRefresh)($this);return $this;}function unsetRelation($k){return $this;}}}
namespace Illuminate\Notifications {trait Notifiable{}}
namespace App\Models {class Setting {static function whereKey($k){return new \QA\Query($k);}static function valueOf($k){return [];}}}
namespace App\Support {
 class R19Store {public static array $rows=[];static function get($k){return self::$rows[$k]??null;}static function documents($p){return array_values(array_filter(self::$rows,fn($v,$k)=>str_starts_with($k,$p),ARRAY_FILTER_USE_BOTH));}}
 class Audit {public static array $rows=[];static function record(...$a){self::$rows[]=array_slice($a,1);}}
}
namespace Illuminate\Support\Facades {
 class DB {static function transaction($fn,$n=1){return $fn();}static function table($t){return new \QA\Query;}}
 class Hash {static function check($p,$h){return password_verify($p,$h);}}
 class Crypt {public static $engine;static function decryptString($s){return self::$engine->decryptString($s);}static function encryptString($s){return self::$engine->encryptString($s);}}
}
namespace Illuminate\Http {class Request {function __construct(public bool $secure=false, public array $values=[]){}function isSecure(){return $this->secure;}function has($k){return array_key_exists($k,$this->values);}}}
namespace App\Http\Controllers {class Controller {}}
namespace {
$B=dirname(__DIR__,2);$GLOBALS['checks']=[];
require $B.'/vendor/symfony/polyfill-mbstring/Mbstring.php';require $B.'/vendor/symfony/polyfill-mbstring/bootstrap.php';
// Load only the encryption contracts and primitive, not vendor/autoload or Laravel.
foreach(['Encrypter','StringEncrypter','DecryptException','EncryptException'] as $n)require $B.'/vendor/laravel/framework/src/Illuminate/Contracts/Encryption/'.$n.'.php';
require $B.'/vendor/laravel/framework/src/Illuminate/Encryption/Encrypter.php';
require $B.'/app/Models/User.php';require $B.'/app/Services/R31/ItArchive.php';require $B.'/app/Http/Controllers/R31ItController.php';
use App\Models\User;use App\Services\R31\ItArchive as IT;use App\Support\{R19Store,Audit};use Illuminate\Support\Facades\Crypt;use function QA\{ck,denied};
$key=random_bytes(32);Crypt::$engine=new Illuminate\Encryption\Encrypter($key,'AES-256-CBC');$plain=json_encode(['password'=>'  TEST-ONLY/شركة-Secret  ','recoveryCodes'=>'0000 TEST ONLY','twoFactorNotes'=>'QA']);$encrypted=Crypt::encryptString($plain);
ck('Real Laravel encryption round trip preserves exact whitespace',Crypt::decryptString($encrypted)===$plain);
ck('Ciphertext excludes cleartext',!str_contains($encrypted,'TEST-ONLY')&&!str_contains(base64_decode($encrypted),'TEST-ONLY'));
ck('Fresh IV gives distinct ciphertext',Crypt::encryptString($plain)!==$encrypted);
try{$bad=json_decode(base64_decode($encrypted),true);$bad['value'][0]=$bad['value'][0]==='a'?'b':'a';Crypt::decryptString(base64_encode(json_encode($bad)));ck('Tampering rejected',false);}catch(Illuminate\Contracts\Encryption\DecryptException $e){ck('Tampering rejected',true);}
try{(new Illuminate\Encryption\Encrypter(random_bytes(32),'AES-256-CBC'))->decryptString($encrypted);ck('Wrong key rejected',false);}catch(Illuminate\Contracts\Encryption\DecryptException $e){ck('Wrong key rejected',true);}
function user():User{$u=new User;$u->id='U31';$u->name='QA';$u->active=true;$u->must_change=false;$u->is_root=false;$u->role_id='role_admin';$u->employee_id='';$u->department='تكنولوجيا المعلومات';$u->role=(object)['data'=>['scope'=>'all','globals'=>[]]];$u->data=[];$u->password=password_hash('QA-LOGIN-123',PASSWORD_DEFAULT);return $u;}
$u=user();ck('IT canonical department maps to real module',User::departmentApp('تكنولوجيا المعلومات')==='it');ck('General all-scope administrator has no implicit vault grant',!$u->globalPermission('viewITSecrets'));ck('General administrator has no implicit credential-write grant',!$u->globalPermission('manageITAccounts'));
R19Store::$rows=['r31:it:accounts:IT-TEST-0001'=>['id'=>'IT-TEST-0001','name'=>'Test account','username'=>'qa@example.invalid','hasSecret'=>true,'_version'=>1],'r31:vault:IT-TEST-0001'=>['ciphertext'=>$encrypted,'_version'=>1]];
denied('IT manager scope alone cannot reveal secrets',fn()=>IT::reveal($u,'IT-TEST-0001','QA-LOGIN-123'));
$u->data=['globalOverrides'=>['viewITSecrets'=>true]];$before=count(Audit::$rows);$v=IT::reveal($u,'IT-TEST-0001','WRONG');ck('Wrong current password gives no secret',($v['denied']??false)&&!isset($v['secret']));ck('Denied reveal is audited',count(Audit::$rows)===$before+1&&Audit::$rows[array_key_last(Audit::$rows)][3]==='reveal-denied');
$v=IT::reveal($u,'IT-TEST-0001','QA-LOGIN-123');ck('Explicit grant plus current password permits reveal',$v['secret']['password']==='  TEST-ONLY/شركة-Secret  '&&$v['clearAfterSeconds']===30);ck('Audit rows never contain secret or entered password',!str_contains(json_encode(Audit::$rows),'TEST-ONLY')&&!str_contains(json_encode(Audit::$rows),'QA-LOGIN'));
$u->data['moduleOverrides']=['it'=>['details'=>false]];denied('Explicit module details denial overrides vault grant',fn()=>IT::reveal($u,'IT-TEST-0001','QA-LOGIN-123'));
unset($u->data['moduleOverrides']);$u->active=false;denied('Inactive holder of grant blocked',fn()=>IT::reveal($u,'IT-TEST-0001','QA-LOGIN-123'));$u->active=true;$u->must_change=true;denied('Forced password change blocks vault',fn()=>IT::reveal($u,'IT-TEST-0001','QA-LOGIN-123'));$u->must_change=false;
$u->onRefresh=function($u){$u->data=['globalOverrides'=>['viewITSecrets'=>false]];};denied('Grant revoked while acquiring business lock is rechecked',fn()=>IT::reveal($u,'IT-TEST-0001','QA-LOGIN-123'));unset($u->onRefresh);
$pub=IT::publicRecord(['id'=>'A','password'=>'x','secret'=>'x','ciphertext'=>'x','secretCipher'=>'x','recoveryCodes'=>'x','twoFactorNotes'=>'x','current_password'=>'x','token'=>'x','hasSecret'=>true]);ck('Public record strips credential fields',$pub===['id'=>'A','hasSecret'=>true]);
$u=user();$u->data['moduleOverrides']=['it'=>['details'=>false]];$list=IT::index($u,'accounts');ck('List-only role receives no record internals or ciphertext',!isset($list[0]['history'])&&!isset($list[0]['ciphertext'])&&!isset($list[0]['hasSecret']));
$u=user();ck('Authorized IT file link can be read',IT::canReadFile($u,'r31-it-accounts','IT-TEST-0001'));$u->data['moduleOverrides']=['it'=>['attachments'=>false]];ck('Attachment explicit deny honored',!IT::canReadFile($u,'r31-it-accounts','IT-TEST-0001'));$u=user();ck('Unknown file resource blocked',!IT::canReadFile($u,'r31-it-vault','IT-TEST-0001'));ck('Missing IT record has no file access',!IT::canReadFile($u,'r31-it-accounts','IT-MISSING-0001'));
$controller=new App\Http\Controllers\R31ItController;
denied('Actual controller blocks reveal over HTTP',fn()=>$controller->secret(new Illuminate\Http\Request(false),'IT-TEST-0001'),426);
denied('Actual controller blocks secret creation over HTTP',fn()=>$controller->save(new Illuminate\Http\Request(false,['secret'=>['password'=>'TEST']]),'accounts'),426);
$m=new ReflectionMethod($controller,'secure');$m->invoke($controller,new Illuminate\Http\Request(true));ck('Controller secure-transport check accepts HTTPS',true);
$checks=$GLOBALS['checks'];$passed=count(array_filter($checks,fn($x)=>$x['passed']));echo json_encode(compact('passed','checks')+['total'=>count($checks),'boundary'=>'Real production User, ItArchive access/reveal, HTTPS guard and Laravel encryption primitive; synthetic ORM/store/identity/Request/DB. Not Laravel integration, SQL or real HTTPS.'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);exit($passed===count($checks)?0:1);
}

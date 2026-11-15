<?php
/** Actual User, RecordAccess, BusinessRules and SocialAccess. Model/role storage simulated only. */
namespace Illuminate\Foundation\Auth {#[\AllowDynamicProperties]class User {public bool $active=true,$is_root=false,$must_change=false;public $role_id='employee',$data=[],$role=null,$employee=null,$employee_id='',$department='';}}
namespace Illuminate\Notifications {trait Notifiable{}}
namespace App\Models {class Setting{static function valueOf($k){return[];}}}
namespace App\Support {class DepartmentManagers{static function normalize($v){return$v;}}}
namespace {
function data_get($v,$k,$default=null){foreach(explode('.',$k)as$p){if(is_array($v)&&array_key_exists($p,$v))$v=$v[$p];elseif(is_object($v)&&isset($v->$p))$v=$v->$p;else return$default;}return$v;}
class Rejected extends \RuntimeException{function __construct(public int $status,string$m){parent::__construct($m);}}
function abort($s,$m=''){throw new Rejected($s,$m);}function abort_unless($v,$s,$m=''){if(!$v)abort($s,$m);}
function ck($n,$v){$GLOBALS['checks'][]=['name'=>$n,'passed'=>(bool)$v];echo($v?'PASS ':'FAIL ').$n."\n";}
function config($k,$d=null){return$d;}
require dirname(__DIR__,2).'/app/Models/User.php';require dirname(__DIR__,2).'/app/Support/RecordAccess.php';require dirname(__DIR__,2).'/app/Support/BusinessRules.php';require dirname(__DIR__,2).'/app/Services/Instagram/SocialAccess.php';
use App\Models\User;use App\Support\{RecordAccess,BusinessRules};use App\Services\Instagram\SocialAccess;
$GLOBALS['checks']=[];$u=new User;$u->employee_id='E1';$u->role=(object)['data'=>['scope'=>'department_limited','scopeActions'=>['view'=>true,'status'=>true,'edit'=>true,'create'=>true],'modules'=>['marketing'=>['view'=>true,'create'=>true,'edit'=>true]],'globals'=>[]]];$u->department='التسويق';
ck('Social department maps consistently',User::departmentApp('السوشال ميديا')==='social'&&User::departmentApp('السوشل ميديا')==='social');
ck('Plain marketing member not automatically granted social workspace',!$u->allows('social','view')&&!SocialAccess::allows($u,'view'));
$u->data=['globalOverrides'=>['connectFacebook'=>true]];
ck('Existing explicitly granted connector keeps access to moved hub',$u->allows('social','view')&&SocialAccess::allows($u,'view')&&SocialAccess::allows($u,'edit'));
ck('Connector permission does not grant direct publishing',!SocialAccess::permission($u,'publishFacebookDirect'));
$u->data['moduleOverrides']['social']['view']=false;
ck('Explicit social denial overrides old marketing access',!$u->allows('social','view')&&!SocialAccess::allows($u,'edit'));
$u->data=['moduleOverrides'=>['social'=>['view'=>true,'create'=>true]],'globalOverrides'=>['publishInstagramDirect'=>true]];$u->role=(object)['data'=>['modules'=>[],'globals'=>[]]];
ck('Social-only worker can compose with explicit rights',SocialAccess::allows($u,'create')&&SocialAccess::permission($u,'publishInstagramDirect'));
$u->must_change=true;ck('Must-change account cannot publish',!SocialAccess::permission($u,'publishInstagramDirect'));$u->must_change=false;$u->active=false;ck('Inactive account cannot open social module',!$u->allows('social','view')&&!SocialAccess::allows($u,'view'));$u->active=true;
$d=['id'=>'T1','assigneeId'=>'E1','employeeId'=>'OLD','department'=>'التسويق','status'=>'in_progress','employeeState'=>'paused'];
ck('Server task ownership prefers assigneeId to obsolete employeeId',RecordAccess::taskOwn($u,$d));
ck('Paused own task remains readable without department-wide rights',RecordAccess::readable($u,'tasks',$d));
$d['assigneeId']='OTHER';$d['employeeId']='E1';ck('Former employeeId does not leak reassigned task',!RecordAccess::taskOwn($u,$d)&&!RecordAccess::readable($u,'tasks',$d));
$d['assigneeId']='';ck('Explicit unassigned record not claimed by legacy employeeId',!RecordAccess::taskOwn($u,$d));unset($d['assigneeId']);ck('Legacy ID-only assignment supported read-only',RecordAccess::taskOwn($u,$d));
$u->data=['moduleOverrides'=>['marketing'=>['details'=>false]]];$d['assigneeId']='E1';ck('Explicit denial still blocks own task details',!RecordAccess::readable($u,'tasks',$d,'details'));
foreach([false,true]as$root){$u->is_root=$root;$u->role_id=$root?'app_programmer':'employee';try{BusinessRules::deleting($u,'employees',['id'=>'E1']);ck('Employee hard delete blocked '.($root?'root':'staff'),false);}catch(Rejected$e){ck('Employee hard delete blocked '.($root?'root':'staff'),$e->status===422);}}
$spec=json_decode(file_get_contents(dirname(__DIR__,2).'/resources/api-spec.json'),true);ck('Both customer aliases persist in allowed fields',in_array('nameAr',$spec['resources']['customers']['fields'],true)&&in_array('nameEn',$spec['resources']['customers']['fields'],true));
$r=['checks'=>$GLOBALS['checks'],'passed'=>count(array_filter($GLOBALS['checks'],fn($c)=>$c['passed'])),'total'=>count($GLOBALS['checks']),'boundary'=>'Actual rule methods; synthetic model attributes and identity, no Laravel SQL/HTTP.'];echo json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";exit($r['passed']===$r['total']?0:1);
}

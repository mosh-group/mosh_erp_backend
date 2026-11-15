<?php
/** Production authorization, file gate and payroll integration rules; identity/storage are explicit in-memory doubles. */
namespace QA {
 class HttpError extends \RuntimeException {function __construct(public int $status,$m=''){parent::__construct($m);}}
 class Query {function __construct(public array $rows){} function where($k,$v){return new self(array_values(array_filter($this->rows,fn($r)=>($r->$k??null)===$v)));} function get(){return new Collection($this->rows);} function firstOrFail(){return $this->rows[0]??throw new HttpError(404);} function exists(){return (bool)$this->rows;} }
 class Collection implements \IteratorAggregate {function __construct(public array $rows){}function getIterator():\Traversable{return new \ArrayIterator($this->rows);}function contains($fn){foreach($this->rows as$r)if($fn($r))return true;return false;}function isEmpty(){return !$this->rows;}}
 function ck($n,$v){$GLOBALS['checks'][]=['name'=>$n,'passed'=>(bool)$v];}
 function denied($n,$fn,$code=403){try{$fn();ck($n,false);}catch(\Throwable $e){ck($n,$e instanceof HttpError&&$e->status===$code);}}
}
namespace {function data_get($a,$p,$d=null){foreach(explode('.',$p)as$k){if(is_array($a)&&array_key_exists($k,$a))$a=$a[$k];elseif(is_object($a)&&isset($a->$k))$a=$a->$k;else return $d;}return $a;}function abort_unless($v,$c,$m=''){if(!$v)throw new \QA\HttpError($c,$m);}function abort_if($v,$c,$m=''){abort_unless(!$v,$c,$m);}function config($k,$d=null){return ['mosh.spec.resources.employment-contracts.module'=>'hr','mosh.spec.resources.customers.module'=>'sales'][$k]??$d;}}
namespace App\Models {
 class User {public bool $active=true,$isRoot=false;public string $id='U1',$employee_id='E1',$department='التصميم';public array $data=[],$permissions=[],$managed=[];public $role=null;
 function root(){return $this->isRoot;}function allows($m,$a='view'){return $this->active&&($this->isRoot||($this->permissions[$m.'.'.$a]??false));}function manages($d){return $this->active&&in_array($d,$this->managed,true);}function globalPermission($k){return $this->permissions[$k]??false;}function taskScope(){return 'mine';}function customerPermission($a){return $this->isRoot||($this->permissions['customer.'.$a]??false);}static function departmentApp($d){return ['الموارد البشرية'=>'hr','الإدارة'=>'admin','التصميم'=>'design','المونتاج'=>'editing'][$d]??$d;}}
 class Employee {public string $id='E2',$department='المونتاج';public array $data=[];}
 class EmploymentContract {public string $id='CT1';public array $data=['employeeId'=>'E1','status'=>'archived'];static function find($id){return new self;}function document(){return $this->data+['id'=>$this->id];}static function whereKey($id){return new \QA\Query([new self]);}}
 class StoredFile {public string $id='F1',$logical_key='K1',$owner_id='U1';static function where($k,$v){return new \QA\Query([new self]);}}
 class FileLink {public static array $rows=[];static function where($k,$v){return new \QA\Query(self::$rows);}}
 class Task {static function where(...$args){return new \QA\Query([]);} }
}
namespace Illuminate\Http {class Request {function __construct(private $u){}function user(){return $this->u;}}}
namespace App\Http\Controllers {class Controller {}}
namespace App\Support {
 class R19Store {static array $rows=[];static function get($k){return self::$rows[$k]??null;}static function documents($p){return array_values(array_filter(self::$rows,fn($v,$k)=>str_starts_with($k,$p),ARRAY_FILTER_USE_BOTH));}static function put($k,$v,$n){\abort_unless((self::$rows[$k]['_version']??0)===$n,409);self::$rows[$k]=$v;self::$rows[$k]['_version']=$n+1;return self::$rows[$k];}}
 class R19HrAccess {static function activeEmployee($p){return true;}}
 class Records {static function model($r){return \App\Models\EmploymentContract::class;}}
}
namespace {
$B=dirname(__DIR__,2);$GLOBALS['checks']=[];
require $B.'/app/Services/R29/Rules.php';require $B.'/app/Services/R29/HrWorkflow.php';require $B.'/app/Services/R29/CustomerWork.php';require $B.'/app/Services/R29/PayrollAdjustments.php';require $B.'/app/Support/RecordAccess.php';require $B.'/app/Http/Controllers/FileController.php';
use App\Models\{User,Employee,FileLink};use App\Support\{RecordAccess,R19Store};use App\Services\R29\{HrWorkflow,CustomerWork,PayrollAdjustments};use function QA\{ck,denied};
$u=new User;$contract=['id'=>'CT1','employeeId'=>'E1','status'=>'archived'];
ck('Employee cannot read own archived contract',!RecordAccess::readable($u,'employment-contracts',$contract,'details'));
ck('Employee cannot list own archived contract',!RecordAccess::readable($u,'employment-contracts',$contract,'list'));
denied('Employee cannot edit own contract',fn()=>RecordAccess::write($u,'employment-contracts','edit',$contract,$contract));
$u->permissions=['hr.details'=>true,'hr.edit'=>true];ck('HR permission alone outside HR department does not expose contracts',!RecordAccess::readable($u,'employment-contracts',$contract,'details'));
$u->department='الموارد البشرية';ck('Authorized HR can read contract',RecordAccess::readable($u,'employment-contracts',$contract,'details'));
$u->data=['moduleOverrides'=>['hr'=>['details'=>false]]];ck('Explicit HR contract read denial honored',!RecordAccess::readable($u,'employment-contracts',$contract,'details'));$u->data=[];
$u->active=false;ck('Inactive HR cannot read contracts',!RecordAccess::readable($u,'employment-contracts',$contract,'details'));$u->active=true;
$u->department='التصميم';$u->permissions=[];$f=new App\Http\Controllers\FileController;$method=new ReflectionMethod($f,'find');
FileLink::$rows=[(object)['resource'=>'employment-contracts','record_id'=>'CT1'],(object)['resource'=>'employees','record_id'=>'E1']];
denied('File owner cannot bypass contract link restriction',fn()=>$method->invoke($f,new Illuminate\Http\Request($u),'K1'),404);
FileLink::$rows=[(object)['resource'=>'r29-contract-scan','record_id'=>'CT1']];denied('Employee blocked from signed contract scan bytes',fn()=>$method->invoke($f,new Illuminate\Http\Request($u),'K1'),404);
$u->department='الموارد البشرية';$u->permissions=['hr.details'=>true];ck('Authorized HR can open linked signed scan',$method->invoke($f,new Illuminate\Http\Request($u),'K1')->id==='F1');
$u=new User;$u->managed=['المونتاج'];$u->permissions=['editing.approve'=>true];$p=new Employee;ck('Direct department manager can evaluate team member',HrWorkflow::canEvaluate($u,$p));$p->id='E1';ck('Manager cannot self evaluate',!HrWorkflow::canEvaluate($u,$p));$p->id='E2';$u->active=false;ck('Inactive manager cannot evaluate',!HrWorkflow::canEvaluate($u,$p));
$u=new User;$u->department='الإدارة';$u->permissions=['admin.view'=>true,'admin.reports'=>true];ck('Administration read-only tracking capability',CustomerWork::canTrack($u));ck('Administration can read minimal customer without Sales write grant',CustomerWork::canReadCustomer($u,['id'=>'C1']));
denied('Read-only tracking does not grant customer edits',fn()=>RecordAccess::write($u,'customers','edit',['id'=>'C1','name'=>'old'],['id'=>'C1','name'=>'new']));
denied('Read-only tracking does not grant cross-department task edits',fn()=>RecordAccess::write($u,'tasks','edit',['id'=>'T1','department'=>'المونتاج','assigneeId'=>'E2'],['id'=>'T1','department'=>'المونتاج','assigneeId'=>'E2','notes'=>'write']));
function seed_pay(){R19Store::$rows=['r29:settlement:S1'=>['id'=>'S1','employeeId'=>'E1','currency'=>'USD','reference'=>'R1','status'=>'approved','_version'=>1,'installments'=>[['index'=>1,'period'=>'2026-10','amountMinor'=>10001,'hoursId'=>null]]],'r29:settlement:S2'=>['id'=>'S2','employeeId'=>'E1','currency'=>'USD','reference'=>'R2','status'=>'approved','_version'=>1,'installments'=>[['index'=>1,'period'=>'2026-10','amountMinor'=>-3000,'hoursId'=>null]]]];}
function hours(){return ['id'=>'H1','employeeId'=>'E1','currency'=>'USD','period'=>'2026-10','status'=>'draft','calculation'=>['gross'=>100000,'net'=>90000,'deductions'=>10000]];}
seed_pay();$h=hours();PayrollAdjustments::apply($h,null);ck('Approved credit and deduction use exact minor units',$h['calculation']['net']===97001&&$h['calculation']['r29Credit']===10001&&$h['calculation']['r29Debit']===3000);
$before=$h;$h['status']='approved';PayrollAdjustments::afterHours($h,$before);ck('Approval reserves installment to one hours record',R19Store::$rows['r29:settlement:S1']['installments'][0]['hoursId']==='H1');
$h2=hours();$h2['id']='H2';PayrollAdjustments::apply($h2,null);ck('Reserved installment not counted in a different draft',!isset($h2['calculation']['r29Adjustments']));
$replay=hours();$replay['status']='approved';PayrollAdjustments::apply($replay,$h);ck('Approved record reuses its frozen adjustments',$replay['calculation']['r29Adjustments']===$h['calculation']['r29Adjustments']);
$sup=$h;$sup['status']='superseded';PayrollAdjustments::afterHours($sup,$h);ck('Superseded eligible hours release installment reservation',R19Store::$rows['r29:settlement:S1']['installments'][0]['hoursId']===null);
seed_pay();R19Store::$rows['r29:settlement:S1']['status']='pending_hr';R19Store::$rows['r29:settlement:S2']['status']='rejected';$h=hours();PayrollAdjustments::apply($h,null);ck('Pending/rejected adjustments never affect payroll',!isset($h['calculation']['r29Adjustments']));
seed_pay();$h=hours();$h['currency']='SYP';PayrollAdjustments::apply($h,null);ck('No cross-currency settlement mixing',!isset($h['calculation']['r29Adjustments']));
seed_pay();$h=hours();$h['employeeId']='E2';PayrollAdjustments::apply($h,null);ck('No cross-employee settlement mixing',!isset($h['calculation']['r29Adjustments']));
seed_pay();$h=hours();$h['period']='2026-09';PayrollAdjustments::apply($h,null);ck('Future installment not applied early',!isset($h['calculation']['r29Adjustments']));
seed_pay();R19Store::$rows['r29:settlement:S2']['installments'][0]['amountMinor']=-200000;$h=hours();denied('Deduction exceeding net salary blocked',function()use(&$h){PayrollAdjustments::apply($h,null);},422);
$checks=$GLOBALS['checks'];$passed=count(array_filter($checks,fn($c)=>$c['passed']));echo json_encode(compact('passed','checks')+['total'=>count($checks),'boundary'=>'Actual RecordAccess/HrWorkflow/file gate/payroll functions with synthetic identity and in-memory storage; not ORM/SQL/HTTP integration.'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);exit($passed===count($checks)?0:1);
}

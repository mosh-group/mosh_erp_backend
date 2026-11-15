<?php
namespace App\Http\Controllers;
use App\Models\{Employee,User,Setting};
use App\Support\{R19Store,RecordAccess,Audit,DepartmentManagers};
use Illuminate\Http\Request;
class EmployeeLifecycleController extends Controller
{
 public function index(Request $r) {
  $u=$r->user();abort_unless($u->active&&!$u->must_change&&($u->root()||$u->allows('hr','details')),403);
  $rows=Employee::whereNotNull('data->archivedAt')->orderBy('created_at')->get()->map(fn($p)=>RecordAccess::document($u,'employees',$p->document()))->values();
  return $this->ok($rows);
 }
 public function update(Request $r) {
  $u=$r->user();abort_unless($u->active&&!$u->must_change&&($u->root()||$u->allows('hr','delete')),403,'أرشفة الموظف واستعادته تحتاج صلاحية أرشفة الموظفين في الموارد البشرية.');
  $v=$r->validate(['employeeId'=>'required|string|max:190','action'=>'required|in:archive,restore','_version'=>'required|integer|min:1','requestId'=>'required|string|min:8|max:128']);
  $result=R19Store::commit($u,'employee-lifecycle',$v,function($tx)use($u,$v){
   abort_unless($u->root()||$u->allows('hr','delete'),403);$p=Employee::findOrFail($v['employeeId']);
   abort_if((string)$p->id===(string)$u->employee_id||User::where('employee_id',$p->id)->where('is_root',true)->exists(),422,'لا يمكن أرشفة ملف الحساب الأساسي أو حسابك الحالي.');
   abort_unless($p->version===$v['_version'],409,'تغير ملف الموظف؛ حدّث البيانات ثم أعد المحاولة.');
   $before=$p->document();$d=$p->data;$archive=$v['action']==='archive';
   abort_if($archive?!empty($d['archivedAt']):empty($d['archivedAt']),409,$archive?'الموظف مؤرشف مسبقاً.':'الموظف غير مؤرشف.');
   $d['active']=false;$d['employmentStatus']='inactive';$d['isManager']=false;
   if($archive){$d['archivedAt']=now()->toIso8601String();$d['archivedBy']=$u->name;}
   else{$d['archivedAt']=null;$d['archivedBy']=null;}
   $d['updatedAt']=now()->toIso8601String();$d['updatedBy']=$u->name;$p->update(['data'=>$d,'version'=>$p->version+1]);
   $changes=[['resource'=>'employees','id'=>(string)$p->id,'data'=>$archive?null:RecordAccess::document($u,'employees',$p->document())]];
   if($archive){
    foreach(User::where('employee_id',$p->id)->get() as $account){$account->update(['active'=>false,'auth_version'=>$account->auth_version+1,'version'=>$account->version+1]);Audit::record($u,'security','users',$account->id,'employee-archived',null,['active'=>false],$tx);}
    $setting=Setting::findOrFail('department-managers');$map=DepartmentManagers::normalize($setting->value);$next=array_filter($map,fn($id)=>(string)$id!==(string)$p->id);
    if($next!==$map){$setting->update(['value'=>$next,'version'=>$setting->version+1]);$changes[]=['resource'=>'settings/department-managers','id'=>'department-managers','data'=>['value'=>(object)$next,'_version'=>$setting->version]];}
    foreach(Employee::where('id','!=',$p->id)->whereNull('data->archivedAt')->get() as $person){$data=$person->data;if(!in_array((string)($data['directManager']??''),[(string)$p->id,(string)($d['name']??'')],true))continue;$data['directManager']='';$person->update(['data'=>$data,'version'=>$person->version+1]);$changes[]=['resource'=>'employees','id'=>(string)$person->id,'data'=>RecordAccess::document($u,'employees',$person->document())];}
   }
   Audit::record($u,'hr','employees',$p->id,$v['action'],$before,$p->document(),$tx);
   return ['employeeId'=>(string)$p->id,'changes'=>$changes];
  });
  // Idempotent replays re-read current records; never restore an old version in the UI.
  $changes=[];foreach($result['changes'] as $change){if($change['resource']==='employees'){$p=Employee::find($change['id']);$change['data']=$p&&empty($p->data['archivedAt'])?RecordAccess::document($u,'employees',$p->document()):null;}else{$s=Setting::findOrFail('department-managers');$change['data']=['value'=>(object)DepartmentManagers::normalize($s->value),'_version'=>$s->version];}$changes[]=$change;}
  return $this->ok(['employeeId'=>$result['employeeId'],'changes'=>$changes]);
 }
}

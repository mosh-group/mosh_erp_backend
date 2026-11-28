<?php
namespace App\Services\R34;
use App\Models\{User,HrRequest,HrApproval,StockItem,StockMovement,Warehouse,Setting};
use App\Support\{R19Store,RecordAccess,Records,BusinessRules,StockRules,Audit};
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
final class Approvals
{
    public const DAMAGE='_r34_damage_';
    public static function canStock(User $u,?string $wid=null): bool
    {
        if(!$u->active||$u->must_change)return false;
        if(data_get($u->data,'moduleOverrides.warehouses.edit')===false)return false;
        if($u->root()||$u->allows('warehouses','edit')||$u->allows('logistics','edit'))return true;
        if($wid)return (string)(Warehouse::find($wid)?->data['managerId']??'')!==''&&in_array((string)(Warehouse::find($wid)?->data['managerId']??''),[(string)$u->employee_id,(string)$u->id],true);
        return Warehouse::where('data->managerId',$u->employee_id)->exists();
    }
    private static function current(array $d): ?array {return $d['approvers'][(int)($d['position']??0)]??null;}
    private static function assigned(User $a,array $d): bool
    {
        $p=self::current($d);return $a->active&&!$a->must_change&&$p&&(string)$p['userId']===(string)$a->id&&(string)$p['employeeId']===(string)$a->employee_id&&$d['requestedById']!==(string)$a->id;
    }
    private static function damageView(User $a,array $d): ?array
    {
        $visible=$d['requestedById']===(string)$a->id||self::canStock($a,$d['from'])||collect($d['approvers'])->contains(fn($p)=>(string)$p['userId']===(string)$a->id&&(string)$p['employeeId']===(string)$a->employee_id);
        if(!$visible)return null;
        return $d+['canDecide'=>($d['status']??'')==='pending'&&self::assigned($a,$d),'canExecute'=>($d['status']??'')==='approved'&&self::canStock($a,$d['from'])];
    }
    private static function isHrDepartmentStage(string $resource,array $data): bool
    {
        return $resource==='hr-requests'
            && ($data['route'][$data['stageIndex']??0]??'')==='الموارد البشرية';
    }

    private static function canDecideHrStage(User $actor,string $resource,array $data): bool
    {
        if (RecordAccess::own($actor,$data)) return false;
        if (self::isHrDepartmentStage($resource,$data) && !Hierarchy::currentHrApprover($data)) {
            return $actor->allows('hr','approve');
        }
        return Hierarchy::isHrApprover($actor,$data);
    }
    public static function overview(User $a): array
    {
        abort_unless($a->active&&!$a->must_change,403);$damages=[];
        $hr = Setting::readOnlySnapshot(function () use ($a) {
            $rows = [];
            foreach (['hr-requests'=>HrRequest::class, 'hr-approvals'=>HrApproval::class] as $resource => $model) {
                foreach ($model::orderBy('created_at', 'desc')->get() as $record) {
                    $data = $record->document();
                    $namedRoute = !empty($data['r34Approval']);
                    if ((!$namedRoute && !self::isHrDepartmentStage($resource,$data))
                        || !RecordAccess::readable($a,$resource,$data,'details')) continue;
                    $canDecide = ($data['status'] ?? '') === 'pending' && self::canDecideHrStage($a,$resource,$data);
                    if ($canDecide || ($namedRoute && (Hierarchy::wasHrApprover($a,$data) || RecordAccess::own($a,$data)))) {
                        $rows[] = $data + ['resource'=>$resource, 'canDecide'=>$canDecide];
                    }
                }
            }
            return $rows;
        });
        foreach(R19Store::prefixed(self::DAMAGE)as$d){$v=self::damageView($a,$d);if($v)$damages[]=$v;}
        return ['hr'=>$hr,'damage'=>$damages,'canRequestDamage'=>Hierarchy::get()['enabled']&&self::canStock($a),
            'stockOptions'=>Hierarchy::get()['enabled']&&self::canStock($a)?['warehouses'=>Warehouse::all()->filter(fn($w)=>($w->data['active']??true)&&self::canStock($a,(string)$w->id))->map(fn($w)=>['id'=>(string)$w->id,'name'=>$w->data['name']??''])->values()->all(),'items'=>StockItem::all()->filter(fn($i)=>($i->data['active']??true))->map(fn($i)=>['id'=>(string)$i->id,'name'=>$i->data['name']??''])->values()->all()]:null,
            'pendingCount'=>count(array_filter($hr,fn($x)=>$x['canDecide']))+count(array_filter($damages,fn($x)=>$x['canDecide']))];
    }
    public static function decideHr(User $a,string $resource,string $id,array $in): array
    {
        abort_unless(in_array($resource,['hr-requests','hr-approvals'],true),404);
        Validator::make($in,['_version'=>'required|integer|min:1','action'=>'required|in:approve,reject','reason'=>'nullable|string|max:3000'])->validate();
        R19Store::commit($a,'r34:hr-decision:'.$resource.':'.$id,$in,function($tx)use($a,$resource,$id,$in){
            $r=Records::find($resource,$id);$d=$r->document();
            abort_unless(RecordAccess::readable($a,$resource,$d,'details')&&self::canDecideHrStage($a,$resource,$d),403);
            abort_unless($r->version===(int)$in['_version']&&$d['status']==='pending',409,'تغير الطلب أو أُغلق؛ حدّث البيانات.');
            $reject=$in['action']==='reject';$note=trim((string)($in['reason']??''));abort_if($reject&&$note==='',422,'سبب الرفض مطلوب.');
            $route=$d['route']??$d['levels'];$key=$resource==='hr-requests'?'stageIndex':'currentLevel';$stage=(int)$d[$key];$last=$stage===count($route)-1;
            $status=$reject?'rejected':($last?'approved':'pending');$history=$d['history']??[];
            $fromHr = self::isHrDepartmentStage($resource,$d);
            $label = $fromHr ? ($reject?'رفض الموارد البشرية':'موافقة الموارد البشرية') : ($reject?'رفض المسؤول الأعلى':'موافقة المسؤول الأعلى');
            $history[]=['text'=>$label,'action'=>$in['action'],'note'=>$note,'stage'=>$stage,'actorId'=>(string)$a->id];
            $data=['status'=>$status,$key=>!$reject&&!$last?$stage+1:$stage,'history'=>$history];
            if ($resource==='hr-requests' && ($route[$stage]??'')==='المدير المباشر') {
                $data += ['managerDecision'=>$reject?'rejected':'approved','managerDecisionBy'=>$a->name,
                    'managerDecisionAt'=>now()->toIso8601String(),'managerNote'=>$note];
            }
            $changes=[Records::change($a,['resource'=>$resource,'action'=>'update','id'=>$id,'version'=>$r->version,'data'=>$data],$tx)];
            BusinessRules::afterTransaction($a,$changes,$tx);return ['id'=>$id];
        });return Records::find($resource,$id)->document();
    }
    public static function requestDamage(User $a,array $in): array
    {
        Validator::make($in,['itemId'=>'required|string|max:190','from'=>'required|string|max:190','qty'=>'required|numeric|gt:0|max:1000000000','reason'=>'required|string|min:3|max:3000'])->validate();
        $res=R19Store::commit($a,'r34:damage:request',$in,function($tx)use($a,$in){
            abort_unless(self::canStock($a,$in['from']),403);$item=StockItem::findOrFail($in['itemId']);$wh=Warehouse::findOrFail($in['from']);
            abort_unless(($item->data['active']??true)&&($wh->data['active']??true),422,'الصنف أو المستودع غير فعال.');
            abort_if(($item->data['tracking']??'')==='serial'&&(float)$in['qty']!==1.0,422,'كمية الأصل المرقّم واحدة.');
            abort_unless(StockRules::balance((string)$item->id,(string)$wh->id)>=(float)$in['qty'],422,'الرصيد الحالي لا يكفي.');
            $chain=Hierarchy::approvers((string)$a->employee_id,'damage');$id=(string)Str::ulid();
            $d=['id'=>$id,'itemId'=>(string)$item->id,'itemName'=>$item->data['name']??'','from'=>(string)$wh->id,'warehouseName'=>$wh->data['name']??'',
                'qty'=>(float)$in['qty'],'reason'=>trim($in['reason']),'status'=>'pending','position'=>0,'approvers'=>$chain,
                'hierarchyVersion'=>Hierarchy::get()['_version'],'requestedById'=>(string)$a->id,'requestedEmployeeId'=>(string)$a->employee_id,'requestedBy'=>$a->name,
                'createdAt'=>now()->toIso8601String(),'history'=>[]];
            R19Store::put(self::DAMAGE.$id,$d,0);Audit::record($a,'warehouses','damage-request',$id,'request',null,$d,$tx);return ['id'=>$id];
        });return self::damageView($a,R19Store::get(self::DAMAGE.$res['id']));
    }
    public static function decideDamage(User $a,string $id,array $in): array
    {
        Validator::make($in,['_version'=>'required|integer|min:1','action'=>'required|in:approve,reject','reason'=>'nullable|string|max:3000'])->validate();
        R19Store::commit($a,'r34:damage:decision:'.$id,$in,function($tx)use($a,$id,$in){
            $d=R19Store::get(self::DAMAGE.$id);abort_unless($d,404);abort_unless(self::assigned($a,$d),403);
            abort_unless($d['_version']===(int)$in['_version']&&$d['status']==='pending',409,'الطلب تغير أو أُغلق.');$old=$d;
            $note=trim((string)($in['reason']??''));$reject=$in['action']==='reject';abort_if($reject&&$note==='',422,'سبب الرفض مطلوب.');
            $d['history'][]=['byId'=>(string)$a->id,'by'=>$a->name,'at'=>now()->toIso8601String(),'action'=>$in['action'],'note'=>$note,'stage'=>$d['position']];
            if($reject)$d['status']='rejected';elseif($d['position']===count($d['approvers'])-1)$d['status']='approved';else$d['position']++;
            if($d['status']!=='pending')$d['decidedAt']=now()->toIso8601String();
            R19Store::put(self::DAMAGE.$id,$d,$d['_version']);Audit::record($a,'warehouses','damage-request',$id,$in['action'],$old,$d,$tx);return ['id'=>$id];
        });return self::damageView($a,R19Store::get(self::DAMAGE.$id));
    }
    public static function executeDamage(User $a,string $id,array $in): array
    {
        Validator::make($in,['_version'=>'required|integer|min:1'])->validate();
        R19Store::commit($a,'r34:damage:execute:'.$id,$in,function($tx)use($a,$id,$in){
            $d=R19Store::get(self::DAMAGE.$id);abort_unless($d,404);abort_unless(self::canStock($a,$d['from']),403);
            abort_unless($d['_version']===(int)$in['_version']&&$d['status']==='approved',409,'الطلب غير معتمد أو نُفذ مسبقاً.');$old=$d;
            $moveId='DAMAGE/'.$id;$data=['id'=>$moveId,'type'=>'damage','itemId'=>$d['itemId'],'from'=>$d['from'],'to'=>'','qty'=>$d['qty'],
                'date'=>now()->toDateString(),'reason'=>$d['reason'],'authorization'=>'R34/'.$id];
            $changes=[CommandContext::run('damage',$moveId,fn()=>Records::change($a,['resource'=>'stock-movements','action'=>'create','id'=>$moveId,'data'=>$data],$tx))];
            BusinessRules::afterTransaction($a,$changes,$tx);
            $d['status']='executed';$d['movementId']=$moveId;$d['executedAt']=now()->toIso8601String();$d['executedBy']=$a->name;$d['executedById']=(string)$a->id;
            R19Store::put(self::DAMAGE.$id,$d,$d['_version']);Audit::record($a,'warehouses','damage-request',$id,'execute',$old,$d,$tx);return ['id'=>$id];
        });return self::damageView($a,R19Store::get(self::DAMAGE.$id));
    }
}

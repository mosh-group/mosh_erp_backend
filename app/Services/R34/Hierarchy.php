<?php
namespace App\Services\R34;
use App\Models\{Employee,User,Setting};
use App\Support\{R19Store,R19HrAccess,Audit,DepartmentManagers};
use Illuminate\Support\Facades\Validator;
final class Hierarchy
{
    public const KEY='_r34_reporting_hierarchy';
    public static function get(): array
    {
        return array_replace(['enabled'=>false,'generalManagerId'=>'','executiveId'=>'','reportsTo'=>[],
            'routes'=>['hr'=>'direct','purchases'=>'direct','damage'=>'chain'],'_version'=>0],R19Store::get(self::KEY)??[]);
    }
    public static function configure(User $a): bool
    {
        return $a->active && !$a->must_change && ($a->root()||($a->allows('hr','edit')&&($a->administration()||User::departmentApp($a->department)==='hr')));
    }
    public static function activePeople(): array
    {
        $out=[];foreach(Employee::orderBy('id')->get() as $e)if(R19HrAccess::activeEmployee($e))$out[(string)$e->id]=$e;
        return $out;
    }
    public static function userFor(string $employeeId): ?User
    {
        if($employeeId==='')return null;
        // Ambiguous multiple accounts must be resolved in user administration, never choose the first silently.
        $users=User::where('employee_id',$employeeId)->where('active',true)->get();
        return $users->count()===1 ? $users->first() : null;
    }
    public static function read(User $a): array
    {
        abort_unless($a->active,403);$cfg=self::get();
        $people=[];foreach(self::activePeople()as$id=>$p)$people[]=['id'=>$id,'name'=>$p->data['name']??'',
            'department'=>$p->data['department']??'','hasAccount'=>self::userFor($id)!==null];
        return $cfg+['people'=>$people,'departmentManagers'=>Setting::valueOf('department-managers'),
            'canConfigure'=>self::configure($a),'selfEmployeeId'=>(string)$a->employee_id,
            'isExecutive'=>(string)$a->employee_id!==''&&(string)$a->employee_id===$cfg['executiveId']];
    }
    public static function save(User $a,array $in): array
    {
        abort_unless(self::configure($a),403);
        Validator::make($in,['enabled'=>'required|boolean','generalManagerId'=>'nullable|string|max:190',
            'executiveId'=>'nullable|string|max:190','reportsTo'=>'present|array|max:500','routes'=>'required|array',
            'routes.hr'=>'required|in:direct,chain','routes.purchases'=>'required|in:direct,chain','routes.damage'=>'required|in:direct,chain',
            '_version'=>'required|integer|min:0'])->validate();
        R19Store::commit($a,'r34:hierarchy',$in,function($tx)use($a,$in){
            abort_unless(self::configure($a),403);$old=self::get();
            abort_unless($old['_version']===(int)$in['_version'],409,'تغير الهيكل؛ حدّث الصفحة قبل الحفظ.');
            $people=self::activePeople();$gm=(string)($in['generalManagerId']??'');$exec=(string)($in['executiveId']??'');$parents=$in['reportsTo'];
            $activeIds=array_map('strval',array_keys($people));
            abort_if($gm!==''&&$gm===$exec,422,'المدير العام والمدير التنفيذي منصبان لشخصين مختلفين.');
            if($exec!==''&&$gm!=='')$parents[$exec]=$gm; // Explicit hierarchy save; does not alter employee/department records.
            foreach([$gm,$exec]as$id)if($id!=='')abort_unless(isset($people[$id]),422,'حدد المدير العام والمدير التنفيذي من الموظفين النشطين.');
            if($in['enabled']){
                abort_unless($exec!=='',422,'حدد المدير التنفيذي المستقل عن المدير العام.');
                try{$parents=HierarchyRules::validate($parents,$gm,$activeIds);}catch(\DomainException$e){abort(422,$e->getMessage());}
                foreach(array_unique(array_map('strval',Setting::valueOf('department-managers')))as$id){
                    if($id===''||!isset($people[$id])||$id===$gm)continue;
                    abort_unless(isset($parents[$id]),422,'حدد المدير الأعلى للمدير: '.($people[$id]->data['name']??$id));
                }
                foreach(array_unique(array_merge([$gm,$exec],array_values($parents)))as$id){
                    $name=$people[$id]->data['name']??$id;
                    abort_unless(self::userFor($id),422,'اربط «'.$name.'» بحساب دخول نشط واحد قبل تفعيل التسلسل.');
                }
            } elseif($parents) {
                try{$parents=HierarchyRules::validate($parents,$gm,$activeIds);}catch(\DomainException$e){abort(422,$e->getMessage());}
            }
            $doc=['enabled'=>$in['enabled'],'generalManagerId'=>$gm,'executiveId'=>$exec,'reportsTo'=>$parents,'routes'=>$in['routes'],
                'updatedAt'=>now()->toIso8601String(),'updatedBy'=>$a->name,'updatedById'=>(string)$a->id];
            $saved=R19Store::put(self::KEY,$doc,$old['_version']);
            Audit::record($a,'hr','reporting-hierarchy','hierarchy','update',$old,$saved,$tx,'لا تتغير مسارات الطلبات السابقة.');
            return ['saved'=>true];
        });return self::read($a);
    }
    public static function managerId(Employee $employee): ?string
    {
        $c=self::get();if(!$c['enabled'])return null;
        $eid=(string)$employee->id;if($eid===$c['generalManagerId'])return '';
        if(isset($c['reportsTo'][$eid]))return (string)$c['reportsTo'][$eid];
        $managers=Setting::valueOf('department-managers');
        if(in_array($eid,array_map('strval',$managers),true))return ''; // missing manager-parent is not inferred
        $department=$employee->data['department']??'';
        $id=(string)($managers[$department]??'');
        return $id!==$eid?$id:'';
    }
    public static function approvers(string $employeeId,string $kind): array
    {
        $c=self::get();abort_unless($c['enabled'],422,'يجب إعداد تبعية المدراء أولاً.');
        $e=Employee::find($employeeId);abort_unless($e&&R19HrAccess::activeEmployee($e),422,'ملف الموظف غير متاح.');
        $parents=$c['reportsTo'];$first=self::managerId($e);
        if($first!=='')$parents[$employeeId]=$first;
        try{$ids=HierarchyRules::chain($employeeId,$parents,$c['generalManagerId'],($c['routes'][$kind]??'direct')==='chain');}
        catch(\DomainException$ex){abort(422,$ex->getMessage());}
        abort_unless($ids,422,'لم يحدد مسؤول أعلى لهذا الموظف؛ لا يمكن اعتماد الشخص طلبه بنفسه.');
        $out=[];foreach($ids as$id){$u=self::userFor($id);$p=Employee::find($id);
            if (!$u && $kind === 'hr' && $id === $first) {
                $department = $e->data['department'] ?? '';
                $coverage = DepartmentManagers::coverage();
                $backup = $coverage['acting'][$department] ?? '';
                if (($coverage['primary'][$department] ?? null) === $id && $backup !== '' && $backup !== $employeeId) {
                    // Preserve the named manager's account in the approval route.
                    $linked = User::where('employee_id', $id)->limit(2)->get();
                    if ($linked->count() === 1) $u = $linked->first();
                }
            }
            abort_unless($u&&$p&&R19HrAccess::activeEmployee($p),422,'أحد مسؤولي الاعتماد غير مرتبط بحساب نشط وحيد.');
            $out[]=['employeeId'=>$id,'userId'=>(string)$u->id,'name'=>$u->name];}
        return $out;
    }
    public static function prepareHr(User $a,?array $old,array &$d,string $routeKey='route'): void
    {
        if($old){abort_if(($d['r34Approval']??null)!==($old['r34Approval']??null),403,'مسار الطلب المحفوظ لا يُعدّل من الحفظ العام.');return;}
        unset($d['r34Approval']);$c=self::get();if(!$c['enabled'])return;
        $route=$d[$routeKey]??[];$out=[];$assigned=[];$done=false;
        foreach($route as$stage){
            if(!$done&&is_string($stage)&&in_array($stage,['المدير المباشر','مدير القسم','مدير القسم الحالي'],true)){
                foreach(self::approvers((string)$d['employeeId'],'hr')as$i=>$person){$assigned[(string)count($out)]=$person;$out[]=$i===0?'المدير المباشر':'المدير الأعلى';}$done=true;
            }else{$out[]=$stage;}
        }
        if($done){$d[$routeKey]=$out;$d['r34Approval']=['hierarchyVersion'=>$c['_version'],'byStage'=>$assigned,'subjectId'=>(string)$d['employeeId'],'createdAt'=>now()->toIso8601String()];}
    }
    public static function currentHrApprover(array $d): ?array
    {
        $approver = $d['r34Approval']['byStage'][(string)($d['stageIndex']??$d['currentLevel']??0)]??null;
        if (!$approver || ($d['status']??'') !== 'pending') return $approver;
        $coverage = DepartmentManagers::coverage();
        if (!$coverage['acting']) return $approver;
        $subject = (string)($d['employeeId']??'');
        $department = $d['department']??Employee::find($subject)?->department??'';
        $primary = $coverage['primary'][$department]??'';
        $backup = $coverage['acting'][$department]??'';
        if ($backup==='' || $backup===$subject || $primary!==(string)($approver['employeeId']??'')) return $approver;
        $account = self::userFor($backup);
        return $account ? ['employeeId'=>$backup,'userId'=>(string)$account->id,'name'=>$account->name] : $approver;
    }
    public static function isHrApprover(User $a,array $d): bool
    {
        if(!$a->active||$a->must_change||(string)$a->employee_id===(string)($d['employeeId']??''))return false;
        $p=self::currentHrApprover($d);return $p && (string)$a->id===(string)$p['userId']&&(string)$a->employee_id===(string)$p['employeeId'];
    }
    public static function wasHrApprover(User $a,array $d): bool
    {
        if(!$a->active||$a->must_change)return false;
        foreach($d['r34Approval']['byStage']??[]as$p)if((string)$p['userId']===(string)$a->id&&(string)$p['employeeId']===(string)$a->employee_id)return true;
        return false;
    }
    public static function guardHrDecision(User $a,array $old,array $new): void
    {
        if(empty($old['r34Approval']))return;
        $changed=($old['status']??'')!==($new['status']??'')||($old['stageIndex']??$old['currentLevel']??0)!==($new['stageIndex']??$new['currentLevel']??0);
        if(!$changed)return;
        abort_if((string)$a->employee_id===(string)($old['employeeId']??''),403,'لا تعتمد أو ترفض طلبك الشخصي بنفسك.');
        if(self::currentHrApprover($old))abort_unless(self::isHrApprover($a,$old)&&\App\Support\RecordAccess::readable($a,isset($old['route'])?'hr-requests':'hr-approvals',$old,'details'),403,'الموافقة من اختصاص المسؤول المحفوظ لهذا الطلب.');
    }
    public static function purchasePolicy(User $a,array $category): array
    {
        $cfg=self::get();if(!$cfg['enabled'])return$category;
        $chain=self::approvers((string)$a->employee_id,'purchases');
        foreach(['quotationApprovers','purchaseApprovers']as$key)abort_if(in_array((string)$a->id,array_map('strval',$category[$key]??[]),true),422,'سياسة الشراء الحالية تتضمن مقدم الطلب كمعتمد؛ اختر سياسة بلا اعتماد ذاتي.');
        $category['_r34Hierarchy']=['hierarchyVersion'=>$cfg['_version'],'requesterUserId'=>(string)$a->id,
            'requesterEmployeeId'=>(string)$a->employee_id,'chain'=>$chain];
        return $category;
    }
    public static function mergePurchaseState(array $state,array $meta): array
    {
        foreach(['quotationApprovers','purchaseApprovers']as$key)$state[$key]=array_values(array_unique(array_merge(array_column($meta['chain'],'userId'),$state[$key]??[])));
        $state['r34Hierarchy']=$meta;return$state;
    }

}

<?php
namespace App\Services\R34;
use App\Models\{Task,User};
use App\Support\{R19Store,Records,RecordAccess,Audit};
use Illuminate\Support\Facades\{Crypt,Validator};
use Illuminate\Support\Str;
final class Cancellation
{
    public const PREFIX='_r34_cancel_task_';
    private const TTL=600;
    private const ATTEMPTS=3;
    public static function manager(User $a,array $t): bool
    {
        return $a->active&&!$a->must_change&&RecordAccess::readable($a,'tasks',$t,'details')&&
            ($a->root()||($a->manages($t['department']??'')&&$a->allows(RecordAccess::module('tasks',$t),'edit')));
    }
    public static function cancellable(array $t): bool
    {
        return in_array($t['status']??'',['new','in_progress'],true) && !in_array($t['employeeState']??'',['finished','submitted'],true);
    }
    public static function fingerprint(array $t): string
    {
        return R19Store::hash(array_intersect_key($t,array_flip(['id','title','description','customer','customerId','department','assigneeId','parentTaskId','sharedDistributionRoot','sharedDistributionChild'])));
    }
    private static function codeHash(string $code,string $id): string {return hash_hmac('sha256',$id.'|'.$code,(string)config('app.key'));}
    private static function row(string $id): array {$r=R19Store::get(self::PREFIX.$id);abort_unless($r,404);return$r;}
    private static function project(User $a,array $r): array
    {
        abort_unless((string)$r['requestedById']===(string)$a->id || (string)$r['executiveUserId']===(string)$a->id || ($a->allows('admin','reports')&&$a->active),403);
        $o=array_intersect_key($r,array_flip(['id','taskId','taskTitle','department','customer','reason','requestedById','requestedBy','executiveUserId','executiveEmployeeId','executiveName','createdAt','expiresAt','status','attempts','decidedAt','decidedBy','rejectReason','_version']));
        if($o['status']==='pending'&&strtotime($r['expiresAt'])<=time())$o['status']='expired';
        $o['canConfirm']=$o['status']==='pending'&&(string)$a->id===(string)$r['requestedById'];
        $o['canReadCode']=$o['status']==='pending'&&(string)$a->id===(string)$r['executiveUserId']&&(string)$a->employee_id===(string)$r['executiveEmployeeId']&&Hierarchy::get()['executiveId']===$r['executiveEmployeeId'];
        $o['attemptsRemaining']=max(0,self::ATTEMPTS-(int)($r['attempts']??0));return$o;
    }
    public static function overview(User $a): array
    {
        abort_unless($a->active&&!$a->must_change,403);$items=[];
        foreach(R19Store::prefixed(self::PREFIX)as$r)if((string)$r['requestedById']===(string)$a->id||(string)$r['executiveUserId']===(string)$a->id)$items[]=self::project($a,$r);
        usort($items,fn($x,$y)=>strcmp($y['createdAt'],$x['createdAt']));
        return ['items'=>$items,'pendingCount'=>count(array_filter($items,fn($r)=>$r['canReadCode']))];
    }
    public static function request(User $a,string $taskId,array $in): array
    {
        Validator::make($in,['reason'=>'required|string|min:3|max:3000'])->validate();
        $result=R19Store::commit($a,'r34:cancel:request:'.$taskId,$in,function($tx)use($a,$taskId,$in){
            $t=Task::findOrFail($taskId);$d=$t->document();abort_unless(self::manager($a,$d),403);
            abort_unless(self::cancellable($d),409,'لا يمكن طلب إلغاء مهمة منجزة أو مؤرشفة أو بانتظار الاعتماد/الفوترة.');
            foreach(Task::where('parent_id',$taskId)->get()as$child)abort_if(($child->data['status']??'')!=='cancelled',409,'راجع المهام الفرعية وألغها بالمصادقة أولاً قبل إلغاء المهمة الأم.');
            $exec=Hierarchy::get()['executiveId'];$u=Hierarchy::userFor($exec);
            abort_unless($u&&$exec!=='',422,'حدد المدير التنفيذي وحسابه من تبعية المدراء أولاً.');
            abort_if((string)$u->id===(string)$a->id||(string)$a->employee_id===$exec,403,'لا يمكن إصدار رمز لاعتماد إلغاء طلبك بنفسك.');
            $recent=0;
            foreach(R19Store::prefixed(self::PREFIX)as$r){
                if($r['taskId']===$taskId&&$r['status']==='pending'&&strtotime($r['expiresAt'])>time())abort(409,'يوجد طلب إلغاء معلق لهذه المهمة؛ افتحه من طلبات الإلغاء.');
                if($r['requestedById']===(string)$a->id&&strtotime($r['createdAt'])>time()-3600)$recent++;
            }
            abort_if($recent>=20,429,'وصلت إلى الحد الوقائي لطلبات الإلغاء؛ أعد المحاولة لاحقاً.');
            $id=(string)Str::ulid();$code=str_pad((string)random_int(0,99999),5,'0',STR_PAD_LEFT);$now=now()->toIso8601String();
            $r=['id'=>$id,'taskId'=>$taskId,'taskTitle'=>$d['title']??'','department'=>$d['department']??'','customer'=>$d['customer']??'',
                'reason'=>trim($in['reason']),'requestedById'=>(string)$a->id,'requestedEmployeeId'=>(string)$a->employee_id,'requestedBy'=>$a->name,
                'executiveUserId'=>(string)$u->id,'executiveEmployeeId'=>$exec,'executiveName'=>$u->name,
                'createdAt'=>$now,'expiresAt'=>now()->addSeconds(self::TTL)->toIso8601String(),'status'=>'pending','attempts'=>0,
                'taskFingerprint'=>self::fingerprint($d),'codeHash'=>self::codeHash($code,$id),'encryptedCode'=>Crypt::encryptString($code)];
            R19Store::put(self::PREFIX.$id,$r,0);
            Audit::record($a,'tasks','task-cancellation',$id,'requested',null,array_diff_key($r,array_flip(['codeHash','encryptedCode'])),$tx);
            return ['id'=>$id];
        });return self::project($a,self::row($result['id']));
    }
    public static function code(User $a,string $id): array
    {
        abort_unless($a->active&&!$a->must_change,403);$r=self::row($id);
        abort_unless((string)$a->id===$r['executiveUserId']&&(string)$a->employee_id===$r['executiveEmployeeId']&&Hierarchy::get()['executiveId']===$r['executiveEmployeeId'],403);
        abort_unless($r['status']==='pending'&&strtotime($r['expiresAt'])>time(),410,'انتهت صلاحية طلب الإلغاء.');
        Audit::record($a,'tasks','task-cancellation',$id,'code-viewed',null,['taskId'=>$r['taskId']]);
        return ['code'=>Crypt::decryptString($r['encryptedCode']),'expiresAt'=>$r['expiresAt'],'request'=>self::project($a,$r)];
    }
    public static function confirm(User $a,string $id,array $in): array
    {
        Validator::make($in,['code'=>['required','string','regex:/^[0-9]{5}$/D']])->validate();
        // Do not persist the secret in a generic transaction hash/input/audit record.
        $safe=['requestId'=>$in['requestId']??'','proof'=>self::codeHash($in['code'],$id)];
        $result=R19Store::commit($a,'r34:cancel:confirm:'.$id,$safe,function($tx)use($a,$id,$in){
            $r=self::row($id);abort_unless((string)$a->id===$r['requestedById']&&(string)$a->employee_id===$r['requestedEmployeeId'],403);
            if($r['status']!=='pending')return ['error'=>'الطلب غير معلق أو استُخدم سابقاً.','http'=>409];
            $fail=function(string$status,string$message,int$http=409)use(&$r,$id){$r['status']=$status;unset($r['encryptedCode'],$r['codeHash']);R19Store::put(self::PREFIX.$id,$r,$r['_version']);return ['error'=>$message,'http'=>$http];};
            if(strtotime($r['expiresAt'])<=time())return $fail('expired','انتهت صلاحية الرمز؛ اطلب مصادقة جديدة.');
            $u=User::find($r['executiveUserId']);
            if(!$u||!$u->active||$u->must_change||(string)$u->employee_id!==$r['executiveEmployeeId']||Hierarchy::get()['executiveId']!==$r['executiveEmployeeId'])return$fail('invalidated','تغير حساب المدير التنفيذي؛ اطلب مصادقة جديدة.');
            $t=Task::findOrFail($r['taskId']);$d=$t->document();abort_unless(self::manager($a,$d),403);
            if(!self::cancellable($d)||!hash_equals($r['taskFingerprint'],self::fingerprint($d)))return$fail('invalidated','تغيرت المهمة منذ الطلب؛ راجعها وأعد طلب المصادقة.');
            if(!hash_equals($r['codeHash'],self::codeHash($in['code'],$id))){
                $r['attempts']++;
                if($r['attempts']>=self::ATTEMPTS)return$fail('locked','انتهت محاولات الرمز وأُبطل الطلب.',429);
                R19Store::put(self::PREFIX.$id,$r,$r['_version']);return ['error'=>'الرمز غير صحيح. المحاولات المتبقية: '.(self::ATTEMPTS-$r['attempts']),'http'=>422];
            }
            foreach(Task::where('parent_id',$r['taskId'])->get()as$child)if(($child->data['status']??'')!=='cancelled')return$fail('invalidated','ظهرت مهمة فرعية لم تُلغ؛ أعد مراجعة المهمة.');
            $at=now()->toIso8601String();$c=['requestId'=>$id,'reason'=>$r['reason'],'requestedBy'=>$r['requestedBy'],
                'requestedById'=>$r['requestedById'],'executedBy'=>$a->name,'executedById'=>(string)$a->id,
                'executiveName'=>$r['executiveName'],'executiveUserId'=>$r['executiveUserId'],'at'=>$at,'method'=>'executive-code'];
            $history=$d['history']??[];$history[]=['text'=>'أُلغيت المهمة بمصادقة رمز المدير التنفيذي. السبب: '.$r['reason']];
            CommandContext::run('cancel-task',(string)$t->id,fn()=>Records::change($a,['resource'=>'tasks','action'=>'update','id'=>(string)$t->id,'version'=>$t->version,
                'data'=>['status'=>'cancelled','cancellation'=>$c,'archivedAt'=>$at,'archivedBy'=>$a->name,'history'=>$history]],$tx));
            $r['status']='approved';$r['decidedAt']=$at;$r['decidedBy']=$a->name;unset($r['encryptedCode'],$r['codeHash']);
            R19Store::put(self::PREFIX.$id,$r,$r['_version']);Audit::record($a,'tasks','task-cancellation',$id,'confirmed',null,$c,$tx);
            return ['taskId'=>(string)$t->id,'requestId'=>$id];
        });
        if(isset($result['error']))abort($result['http'],$result['error']);
        $r=self::row($id);$task=Task::findOrFail($result['taskId']);abort_unless(RecordAccess::readable($a,'tasks',$task->document(),'details'),403);
        return ['request'=>self::project($a,$r),'task'=>$task->document()];
    }
    public static function reject(User $a,string $id,array $in): array
    {
        Validator::make($in,['reason'=>'required|string|min:3|max:3000'])->validate();
        R19Store::commit($a,'r34:cancel:reject:'.$id,$in,function($tx)use($a,$id,$in){
            $r=self::row($id);abort_unless($a->active&&!$a->must_change&&(string)$a->id===$r['executiveUserId']&&(string)$a->employee_id===$r['executiveEmployeeId']&&Hierarchy::get()['executiveId']===$r['executiveEmployeeId'],403);
            abort_unless($r['status']==='pending',409);$r['status']='rejected';$r['rejectReason']=trim($in['reason']);$r['decidedAt']=now()->toIso8601String();$r['decidedBy']=$a->name;
            unset($r['codeHash'],$r['encryptedCode']);R19Store::put(self::PREFIX.$id,$r,$r['_version']);Audit::record($a,'tasks','task-cancellation',$id,'rejected',null,$r,$tx);return ['id'=>$id];
        });return self::project($a,self::row($id));
    }
    public static function archive(User $a): array
    {
        abort_unless($a->active,403);$out=[];
        foreach(Task::where('status','cancelled')->orderBy('updated_at','desc')->get()as$t)if(RecordAccess::readable($a,'tasks',$t->document(),'details'))$out[]=$t->document();
        return ['items'=>$out,'readOnly'=>true];
    }
}

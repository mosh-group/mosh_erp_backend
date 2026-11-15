<?php
namespace App\Support;

use App\Models\{Task,User};

/**
 * R7: execution is timed by the server from employeeState transitions.
 * Called only inside TransactionController's existing atomic/idempotent transaction.
 * Attendance is deliberately not used. Reads never start or repair a timer.
 */
final class TaskExecutionClock
{
    public const MAX_SESSION_MS = 86400000;

    public static function epoch(mixed $value): ?int
    {
        if (is_bool($value) || (!is_scalar($value) && !($value instanceof \DateTimeInterface))) return null;
        if ($value instanceof \DateTimeInterface) $n=(float)$value->format('U.u')*1000;
        elseif (is_numeric($value)) {
            $n=(float)$value;
            if ($n>=946684800 && $n<4102444800) $n*=1000; // historical seconds -> ms
        } else {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/',trim((string)$value))) return null;
            try { $n=(float)(new \DateTimeImmutable((string)$value))->format('U.u')*1000; }
            catch (\Throwable) { return null; }
        }
        return isset($n)&&is_finite($n)&&$n>=946684800000&&$n<4102444800000 ? (int)round($n) : null;
    }

    private static function duration(mixed $value): ?int
    {
        return !is_bool($value)&&is_numeric($value)&&is_finite((float)$value)&&(float)$value>=0&&(float)$value<PHP_INT_MAX
            ? (int)round((float)$value) : null;
    }

    private static function activeStart(array $w): ?int
    {
        $found=[];
        foreach (['activeStartedAt','active_started_at'] as $key) if (($n=self::epoch($w[$key]??null))!==null) $found[]=$n;
        foreach (['activeSession','active_session'] as $key) {
            $s=$w[$key]??null;
            if (is_array($s)&&empty($s['endAt'])&&empty($s['end_at'])&&empty($s['endedAt'])&&empty($s['ended_at'])) {
                $n=self::epoch($s['startAt']??$s['start_at']??$s['startedAt']??$s['started_at']??null);
                if ($n!==null) $found[]=$n;
            }
        }
        return $found&&max($found)-min($found)<=1 ? $found[0] : null;
    }

    private static function total(array $w): ?int
    {
        $found=[];
        foreach (['totalMs'=>1,'total_ms'=>1,'totalSeconds'=>1000,'total_seconds'=>1000] as $key=>$scale) {
            $raw=$w[$key]??null;
            if (!is_bool($raw)&&is_numeric($raw)&&is_finite((float)$raw)&&(float)$raw>=0&&(float)$raw<PHP_INT_MAX/$scale) {
                $found[]=(int)round((float)$raw*$scale); // scale fractional seconds before rounding
            }
        }
        return $found&&max($found)-min($found)<=1 ? $found[0] : null;
    }

    private static function issue(array &$w,?array $old,int $ms,string $reason,User $actor): void
    {
        $before=is_array($old['workTiming']??null)?$old['workTiming']:[];
        $id=hash('sha256',($old['id']??'').'|'.($old['_version']??'').'|'.$reason);
        $w['unmeasuredSessions']=is_array($w['unmeasuredSessions']??null)?$w['unmeasuredSessions']:[];
        if (!in_array($id,array_column($w['unmeasuredSessions'],'id'),true)) {
            $w['unmeasuredSessions'][]=[
                'id'=>$id,'reason'=>$reason,'recordedAt'=>$ms,'recordedById'=>(string)$actor->id,
                'workerId'=>(string)($old['assigneeId']??''),'taskVersion'=>$old['_version']??null,
                'originalTiming'=>array_intersect_key($before,array_flip(['activeStartedAt','active_started_at','activeSession','active_session','totalMs','total_ms','totalSeconds','total_seconds'])),
                'durationMs'=>null, // missing historical work is not zero, nor an invented duration
            ];
        }
        $w['historyIncomplete']=true;
    }

    public static function apply(User $actor,?array $old,array &$d): void
    {
        $before=is_array($old['workTiming']??null)?$old['workTiming']:[];
        $candidate=is_array($d['workTiming']??null)?$d['workTiming']:[];
        $command=$candidate['_command']??null;
        BusinessRules::need($command===null||$command==='repair_missing','أمر توقيت غير صالح.');
        $repair=$command==='repair_missing';
        $ms=now()->getTimestampMs();
        $prior=$old['employeeState']??'new';$state=$d['employeeState']??'new';
        $wasRunning=$prior==='working'&&($old['status']??'in_progress')==='in_progress';
        $assigned=(string)($old['assigneeId']??'')!==(string)($d['assigneeId']??'');
        $start=self::activeStart($before);
        $validStart=$start!==null&&$start<=$ms&&$ms-$start<=self::MAX_SESSION_MS;
        $total=self::total($before);
        $running=$state==='working'&&($d['status']??'in_progress')==='in_progress';
        $begin=$running&&($prior!=='working'||$repair);

        if ($repair) {
            abort_unless($old&&trim((string)$actor->employee_id)!==''&&
                (string)$actor->employee_id===(string)($old['assigneeId']??'')&&
                $actor->allows(RecordAccess::module('tasks',$old),'status'),403,'إصلاح الجلسة للموظف المكلف بصلاحية التنفيذ فقط.');
            BusinessRules::need($prior==='working'&&$running&&!$assigned,'إصلاح التوقيت متاح للمهمة الجاري تنفيذها فقط.');
            abort_if($validStart&&$total!==null,409,'توقيت المهمة سليم بالفعل؛ حدّث البيانات دون إعادة تشغيله.');
        }
        if ($begin) {
            BusinessRules::need(!empty($d['assigneeId']),'أسند المهمة قبل بدء العمل.');
            BusinessRules::need(!Task::where('employee_id',$d['assigneeId'])->where('id','!=',(string)$d['id'])
                ->where('data->status','in_progress')->where('data->employeeState','working')->exists(),
                'توجد مهمة أخرى قيد العمل لهذا الموظف؛ أوقفها مؤقتاً أولاً.');
        }
        BusinessRules::need(!($assigned&&$running),'أوقف التنفيذ قبل نقل المهمة إلى موظف آخر.');

        // Preserve business metadata; all clock values below are server-owned.
        $protected=['_command','sessions','totalMs','total_ms','totalSeconds','total_seconds',
            'activeStartedAt','active_started_at','activeSession','active_session','activeWorker','activeWorkerId',
            'receivedAt','lastReceivedAt','finishedAt','receiptEvents','historyIncomplete','unmeasuredSessions','clockVersion'];
        $w=array_merge($before,array_diff_key($candidate,array_flip($protected)));
        unset($w['_command']);
        $w['sessions']=is_array($before['sessions']??null)?$before['sessions']:[];
        if ($total===null) {
            $total=0;
            foreach ($w['sessions'] as $session) {
                if (!is_array($session)) continue;
                $a=self::epoch($session['startAt']??null);$b=self::epoch($session['endAt']??null);
                $duration=self::duration($session['durationMs']??null);
                if ($a!==null&&$b!==null&&$b>=$a&&$b-$a<=self::MAX_SESSION_MS) $total+=$b-$a;
                elseif ($duration!==null&&$duration<=self::MAX_SESSION_MS) $total+=$duration;
            }
            if ($old&&($before||!in_array($prior,['new','received'],true))) {
                self::issue($w,$old,$ms,'الإجمالي السابق غير متاح؛ المعروض يجمع الفترات المسجلة فقط.',$actor);
            }
        }
        $w['totalMs']=$total;
        $w['activeStartedAt']=$start??0;
        $w['activeWorker']=$before['activeWorker']??'';
        $w['activeWorkerId']=$before['activeWorkerId']??'';

        // Close a real session on pause / submission / reassignment / cancellation.
        // A corrupt legacy start is recorded for review, never counted from epoch zero.
        if (!$running&&($wasRunning||$start!==null)) {
            if ($wasRunning&&$validStart) {
                $duration=$ms-$start;
                $w['sessions'][]=['id'=>'TS-'.hash('sha256',($d['id']??'').'|'.($old['_version']??'').'|'.$start.'|'.$ms),
                    'startAt'=>$start,'endAt'=>$ms,'durationMs'=>$duration,
                    'worker'=>($before['activeWorker']??'')?:($old['assignee']??$actor->name),
                    'workerId'=>($before['activeWorkerId']??'')?:($old['assigneeId']??''),
                    'reason'=>$assigned?'reassigned':($state==='submitted'?'submitted':(($d['status']??'')!=='in_progress'?'closed':'paused'))];
                $w['totalMs']+=$duration;
            } elseif ($old) self::issue($w,$old,$ms,'جلسة سابقة بلا بداية صالحة؛ لم تُحتسب مدتها تلقائياً.',$actor);
            $w['activeStartedAt']=0;$w['activeWorker']='';$w['activeWorkerId']='';
        }
        if ($begin) {
            // Repair only after an explicit confirmed action. Never on GET or unrelated edit.
            if ($repair&&!$validStart) self::issue($w,$old,$ms,'بدأت جلسة جديدة بتأكيد الموظف؛ بداية الجلسة السابقة غير قابلة للحساب.',$actor);
            $w['activeStartedAt']=($repair&&$validStart)?$start:$ms;
            $w['activeWorker']=$d['assignee']??$actor->name;
            $w['activeWorkerId']=(string)$d['assigneeId'];
        }
        if (!$running) {$w['activeStartedAt']=0;$w['activeWorker']='';$w['activeWorkerId']='';}

        // An acknowledgement may leave a returned task in 'paused'; record it without starting.
        $known=array_column($old['workChat']??[],null,'id');$receiptId=null;
        foreach ($d['workChat']??[] as $message) {
            $id=$message['id']??'';
            if (is_string($id)&&str_starts_with($id,'MR49/receive/')&&!isset($known[$id])) $receiptId=$id;
        }
        if (($state==='received'&&$prior==='new')||$receiptId!==null||($prior==='new'&&$running)) {
            if (empty($before['receivedAt'])) $w['receivedAt']=$ms;
            $w['lastReceivedAt']=$ms;
            $w['receiptEvents']=is_array($before['receiptEvents']??null)?$before['receiptEvents']:[];
            $w['receiptEvents'][]=['id'=>$receiptId??'received-'.$d['id'].'-'.$ms,'at'=>$ms,'workerId'=>(string)($d['assigneeId']??'')];
        }
        if ($state==='submitted'&&$prior!=='submitted') $w['finishedAt']=$ms;
        // Drop competing aliases after canonicalization; the audit log retains the original row.
        foreach (['active_started_at','activeSession','active_session','total_ms','totalSeconds','total_seconds'] as $key) unset($w[$key]);
        $w['clockVersion']='server-r7';$d['workTiming']=$w;
    }
}

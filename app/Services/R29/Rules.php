<?php
namespace App\Services\R29;

/** Domain calculations have no network, database or browser dependencies. */
final class Rules
{
    public static function need(bool $ok, string $message): void { if (!$ok) throw new \DomainException($message); }
    public static function date(string $v): bool {
        $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$v,new \DateTimeZone('UTC'));
        return $d && $d->format('Y-m-d')===$v;
    }
    public static function day(string $v): \DateTimeImmutable { self::need(self::date($v),'تاريخ غير صالح.'); return new \DateTimeImmutable($v,new \DateTimeZone('UTC')); }
    public static function days(string $from,string $to): int { return max(0,(int)self::day($from)->diff(self::day($to))->format('%r%a')+1); }
    public static function mode(array $p): string { return $p['attendanceMode']??(!empty($p['zktecoExempt'])?'daily':'fingerprint'); }
    public static function activationErrors(array $p): array {
        $out=[];
        foreach (['name'=>'اسم الموظف','department'=>'القسم','jobTitle'=>'المسمى الوظيفي','hireDate'=>'تاريخ التعيين'] as $key=>$label) if(trim((string)($p[$key]??''))==='')$out[$key]='أكمل '.$label;
        if(!empty($p['hireDate'])&&!self::date($p['hireDate']))$out['hireDate']='أدخل تاريخ تعيين صالحاً';
        $mode=self::mode($p);
        if(!in_array($mode,['fingerprint','flexible','daily'],true))$out['attendanceMode']='اختر نظام الدوام';
        if($mode==='fingerprint'&&trim((string)($p['zktecoEnrollmentNumber']??''))==='')$out['zktecoEnrollmentNumber']='أدخل رقم البصمة أو اختر نظاماً معفى';
        if($mode!=='daily'&&(!is_numeric($p['workdayHours']??null)||(float)$p['workdayHours']<=0||(float)$p['workdayHours']>24))$out['workdayHours']='أدخل ساعات يوم العمل من أكثر من صفر وحتى 24';
        return $out;
    }
    public static function activationMissing(array $p): array { return array_values(self::activationErrors($p)); }
    public static function profile(array &$p,?array $old): void {
        self::need(in_array($p['employmentStatus']??'active',['draft','active','inactive','resigned'],true),'حالة الموظف غير صالحة.');
        if(($p['employmentStatus']??'active')==='draft')$p['active']=false;
        if(isset($p['attendanceMode'])){
            self::need(in_array($p['attendanceMode'],['fingerprint','flexible','daily'],true),'نظام الدوام غير صالح.');
            $p['zktecoExempt']=$p['attendanceMode']!=='fingerprint';
            self::need(!$p['zktecoExempt']||empty($p['zktecoEnrollmentNumber']),'امسح رقم البصمة قبل اختيار الدوام المعفى.');
        }
        $becomesActive=($p['employmentStatus']??'active')==='active'&&($p['active']??true)!==false;
        $wasActive=$old&&($old['employmentStatus']??'active')==='active'&&($old['active']??true)!==false;
        // Existing active records are not silently deactivated by installing an update.
        if($becomesActive&&!$wasActive&&($missing=self::activationErrors($p)))throw \Illuminate\Validation\ValidationException::withMessages($missing);
        if($becomesActive&&$wasActive)foreach(['name','department','jobTitle','hireDate']as$k)
            if(!empty($old[$k]))self::need(trim((string)($p[$k]??''))!=='','لا يمكن تفريغ البيانات الأساسية لموظف نشط.');
        foreach($p['leaveUsedBeforeSystemByYear']??[]as$year=>$v){
            self::need(preg_match('/^(19|20|21)\d{2}$/D',(string)$year)===1,'سنة إجازات غير صالحة.');
            self::need(is_array($v)&&is_numeric($v['days']??null)&&(float)$v['days']>=0&&(float)$v['days']<=366,'أيام الإجازة المستخدمة سابقاً غير صالحة.');
            self::need(self::date((string)($v['throughDate']??''))&&substr($v['throughDate'],0,4)===(string)$year,'حدد آخر تاريخ يشمله الاستخدام السابق ضمن السنة نفسها.');
        }
    }
    public static function schedule(array $input): array {
        $type=$input['type']??'weekly';self::need(in_array($type,['weekly','monthly'],true),'نوع الجدول غير صالح.');
        $rows=$input['rows']??[];self::need(is_array($rows)&&count($rows)<=366,'جدول غير صالح.');$seen=[];$out=[];
        foreach($rows as$r){
            self::need(is_array($r),'سطر جدول غير صالح.');$key=(string)($type==='weekly'?($r['day']??''):($r['date']??''));
            self::need($type==='weekly'?preg_match('/^[0-6]$/D',$key)===1:self::date($key),'يوم الجدول غير صالح.');
            self::need(!isset($seen[$key]),'يوجد يوم مكرر في الجدول.');$seen[$key]=true;$off=(bool)($r['off']??false);
            $start=(string)($r['start']??'');$end=(string)($r['end']??'');
            if(!$off){self::need(preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D',$start)===1&&preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D',$end)===1&&$start!==$end,'حدد بداية ونهاية مختلفتين لكل يوم عمل.');}
            $out[]=[$type==='weekly'?'day':'date'=>$type==='weekly'?(int)$key:$key,'off'=>$off,'start'=>$off?'':$start,'end'=>$off?'':$end];
        }
        return ['type'=>$type,'rows'=>$out];
    }
    public static function leave(array $p,int $year,array $policy=[]): array {
        self::need($year>=1900&&$year<=2199,'سنة إجازات غير صالحة.');$from="$year-01-01";$to="$year-12-31";
        $ent=(float)($p['annualLeaveDays']??$policy['annualLeaveDays']??14);
        $prior=$p['leaveUsedBeforeSystemByYear'][(string)$year]??[];$usedBefore=(float)($prior['days']??0);
        $used=0.;$pending=0.;$seen=[];
        foreach($p['leaveRecords']??[]as$r){
            if(!is_array($r))continue;$id=(string)($r['id']??'');if($id!==''&&isset($seen[$id]))continue;if($id!=='')$seen[$id]=true;
            $type=$r['type']??'';if(!in_array($type,['سنوية','annual','annual_leave'],true)&&!(in_array($type,['ساعية','hourly','hourly_leave'],true)&&!empty($r['deductAnnual'])))continue;
            if(!in_array($r['status']??'',['approved','pending','requested'],true))continue;
            $a=(string)($r['startDate']??'');$b=(string)($r['endDate']??$a);if(!self::date($a)||!self::date($b)||$b<$a)continue;
            $start=max($a,$from);$end=min($b,$to);if($end<$start)continue;
            $days=isset($r['days'])&&is_numeric($r['days'])&&(float)$r['days']>0?(float)$r['days']:self::days($a,$b);
            if(!empty($r['deductAnnual'])&&in_array($type,['ساعية','hourly','hourly_leave'],true))$days=(float)($r['hours']??0)/max(1,(float)($r['workdayHours']??$p['workdayHours']??8));
            $amount=$days*self::days($start,$end)/self::days($a,$b);
            if(($r['status']??'')==='approved')$used+=$amount;else$pending+=$amount;
        }
        return ['year'=>$year,'entitlement'=>round($ent,2),'usedBefore'=>round($usedBefore,2),'used'=>round($used+$usedBefore,2),'recordedUsed'=>round($used,2),
            'pending'=>round($pending,2),'available'=>round(max(0,$ent-$used-$usedBefore-$pending),2),'carryForward'=>0,'start'=>$from,'end'=>$to,
            'legacyOpeningBalanceNotApplied'=>(float)($p['leaveOpeningBalance']??0),'previousUseIsAdditional'=>true];
    }
    public static function criteria(array $labels): array {
        self::need(count($labels)===5,'يجب تحديد خمسة معايير بالضبط.');$out=array_map(fn($v)=>trim((string)$v),$labels);
        self::need(count(array_unique($out))===5,'المعايير الخمسة يجب أن تكون مختلفة.');foreach($out as$v)self::need($v!==''&&strlen($v)<=600,'اسم معيار فارغ أو طويل جداً.');return $out;
    }
    public static function evaluation(array $labels,array $scores): array {
        $labels=self::criteria($labels);self::need(count($scores)===5,'أدخل العلامات الخمس.');$values=[];
        foreach($scores as$n){self::need(is_numeric($n)&&(float)$n>=0&&(float)$n<=100,'كل علامة من 0 إلى 100.');$values[]=round((float)$n,2);}
        return ['criteria'=>$labels,'scores'=>$values,'score'=>round(array_sum($values)/5,2)];
    }
    public static function probation(array $p,string $today): array {
        $end=(string)($p['probationEndDate']??'');if(!self::date($end)||!self::date($today)||($p['active']??true)===false||($p['employmentStatus']??'active')!=='active')return [];
        $out=[];$before=self::day($end)->modify('-7 days')->format('Y-m-d');
        foreach(['before7'=>$before,'end'=>$end]as$stage=>$date)if($today>=$date)$out[]=['id'=>hash('sha256',($p['id']??'').'|'.$end.'|'.$stage),'employeeId'=>$p['id']??'','name'=>$p['name']??'','department'=>$p['department']??'','dueDate'=>$date,'endDate'=>$end,'stage'=>$stage];
        return $out;
    }
    public static function installments(int $amount,int $count,string $period): array {
        self::need($count>=1&&$count<=60&&preg_match('/^(19|20|21)\d{2}-(0[1-9]|1[0-2])$/D',$period)===1,'عدد الأقساط أو شهر البدء غير صالح.');
        $sign=$amount<0?-1:1;$base=intdiv(abs($amount),$count);$rest=abs($amount)%$count;$d=self::day($period.'-01');$out=[];
        for($i=0;$i<$count;$i++)$out[]=['index'=>$i+1,'period'=>$d->modify("+$i months")->format('Y-m'),'amountMinor'=>$sign*($base+($i<$rest?1:0)),'hoursId'=>null];return $out;
    }
    /** HR explicitly selects a day-count convention; no statutory formula is assumed. */
    public static function retro(int $old,int $new,string $from,string $to,string $convention): array {
        self::need($to>=$from&&self::days($from,$to)<=3660,'فترة فرق الراتب غير صالحة.');
        self::need(in_array($convention,['actual','thirty'],true),'حدد قاعدة احتساب الجزء من الشهر.');
        $cursor=self::day($from);$last=self::day($to);$rows=[];$sum=0;
        while($cursor<=$last){$end=min($cursor->format('Y-m-t'),$to);$start=$cursor->format('Y-m-d');$days=self::days($start,$end);$den=$convention==='actual'?(int)$cursor->format('t'):30;
            if($start===$cursor->format('Y-m-01')&&$end===$cursor->format('Y-m-t'))$part=$new-$old;else$part=(int)round(($new-$old)*$days/$den,0,PHP_ROUND_HALF_UP);
            $rows[]=['period'=>$cursor->format('Y-m'),'from'=>$start,'to'=>$end,'days'=>$days,'divisor'=>$den,'amountMinor'=>$part];$sum+=$part;$cursor=$cursor->modify('first day of next month');}
        return ['amountMinor'=>$sum,'months'=>$rows,'convention'=>$convention];
    }
}

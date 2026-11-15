<?php
namespace App\Services\R29;
use App\Models\{User,Employee,PayrollHours,FinancialDocument};
use App\Support\{R19Store,Money,Audit};
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** Explicit company calculations, not a statutory end-of-service formula. */
final class PayrollAdjustments
{
    public static function create(User $a,Employee $p,array $in,string $tx): array {
        $v=Validator::make($in,['kind'=>'required|in:salary_difference,settlement,end_of_service','currency'=>'required|string',
            'reference'=>'required|string|max:200','reason'=>'required|string|min:3|max:4000','period'=>['required','regex:/^(19|20|21)\d{2}-(0[1-9]|1[0-2])$/D'],
            'installments'=>'required|integer|min:1|max:60','lines'=>'sometimes|array|max:100','lines.*.label'=>'required|string|max:300',
            'lines.*.amount'=>'required|numeric|min:0','lines.*.direction'=>'required|in:credit,debit',
            'oldSalary'=>'nullable|numeric|min:0','newSalary'=>'nullable|numeric|min:0','from'=>'nullable|date_format:Y-m-d',
            'to'=>'nullable|date_format:Y-m-d|before_or_equal:today','convention'=>'nullable|in:actual,thirty','receiptKey'=>'nullable|string|max:190'])->validate();
        Money::currency($v['currency']);$id=(string)Str::ulid();$total=0;$calculation=[];
        foreach(R19Store::documents('r29:settlement:')as$r)abort_unless(($r['employeeId']??'')!==(string)$p->id||($r['reference']??'')!==trim($v['reference']),409,'مرجع التسوية مستخدم لهذا الموظف.');
        if($v['kind']==='salary_difference'){
            abort_unless(isset($v['oldSalary'],$v['newSalary'],$v['from'],$v['to'],$v['convention']),422,'أدخل الراتب السابق والجديد وفترة السريان وقاعدة تجزئة الشهر.');
            $old=Money::minor($v['oldSalary'],$v['currency']);$new=Money::minor($v['newSalary'],$v['currency']);
            $calculation=Rules::retro($old,$new,$v['from'],$v['to'],$v['convention']);$total=$calculation['amountMinor'];
            // Do not pay overlapping retroactive periods twice.
            foreach(R19Store::documents('r29:settlement:')as$r)if(($r['employeeId']??'')===(string)$p->id&&($r['kind']??'')==='salary_difference'&&!in_array($r['status']??'',['rejected','cancelled'],true))
                abort_unless($v['to']<($r['from']??'0000')||$v['from']>($r['to']??'9999'),409,'توجد تسوية فروقات تغطي جزءاً من هذه الفترة.');
        }else{
            abort_unless(!empty($v['lines']),422,'أدخل بنود الاستحقاقات والاستقطاعات وفق قواعد الشركة.');$items=[];
            foreach($v['lines']as$line){$n=Money::minor($line['amount'],$v['currency']);$signed=$line['direction']==='debit'?-$n:$n;$total+=$signed;$items[]=['label'=>$line['label'],'direction'=>$line['direction'],'amountMinor'=>$n];}
            $calculation=['lines'=>$items,'amountMinor'=>$total,'method'=>'hr_entered_company_basis'];
        }
        abort_unless(($total!==0||$v['kind']!=='salary_difference')&&abs($total)<90000000000000,422,'صافي التسوية يجب ألا يكون صفراً أو يتجاوز الحد.');
        $status=$v['kind']==='salary_difference'&&$total>0?'approved':'pending_hr';
        $r=['id'=>$id,'employeeId'=>(string)$p->id,'employeeName'=>$p->data['name'],'kind'=>$v['kind'],'currency'=>$v['currency'],'reference'=>trim($v['reference']),
            'reason'=>$v['reason'],'from'=>$v['from']??null,'to'=>$v['to']??null,'oldSalary'=>$v['oldSalary']??null,'newSalary'=>$v['newSalary']??null,
            'calculation'=>$calculation,'amountMinor'=>$total,'status'=>$status,'createdAt'=>now()->toIso8601String(),'createdBy'=>$a->name,'createdById'=>(string)$a->id,
            'installments'=>$total===0?[]:Rules::installments($total,(int)$v['installments'],$v['period']), 'receipt'=>null,'approvedBy'=>$status==='approved'?$a->name:null,
            'approvedAt'=>$status==='approved'?now()->toIso8601String():null];
        if(!empty($v['receiptKey'])){$f=\App\Models\StoredFile::where('logical_key',$v['receiptKey'])->firstOrFail();abort_unless($f->owner_id===$a->id,403);\App\Models\FileLink::firstOrCreate(['file_id'=>$f->id,'resource'=>'r29-settlement','record_id'=>$id]);$r['receipt']=['key'=>$f->logical_key,'name'=>$f->name];}
        R19Store::put('r29:settlement:'.$id,$r,0);Audit::record($a,'hr','r29-settlement',$id,'create',null,$r,$tx);return ['settlementId'=>$id];
    }
    public static function decide(User $a,Employee $p,array $in,string $tx): void {
        $v=Validator::make($in,['settlementId'=>'required|string','settlementVersion'=>'required|integer|min:1','decision'=>'required|in:approve,reject,cancel','note'=>'required|string|min:3|max:2000','installments'=>'sometimes|integer|min:1|max:60','period'=>['sometimes','regex:/^(19|20|21)\d{2}-(0[1-9]|1[0-2])$/D']])->validate();
        $r=R19Store::get('r29:settlement:'.$v['settlementId']);abort_unless($r&&$r['employeeId']===(string)$p->id,404);abort_unless($r['_version']===(int)$v['settlementVersion'],409);$old=$r;
        foreach($r['installments']as$i)abort_if(!empty($i['hoursId']),409,'التسوية مرتبطة بساعات راتب؛ ألغِ استخدامها من مسار الرواتب قبل تعديل القرار.');
        if($v['decision']==='cancel')abort_unless(in_array($r['status'],['pending_hr','approved'],true),409);else abort_unless($r['status']==='pending_hr',409,'صدر قرار التسوية سابقاً.');
        if($v['decision']==='approve'&&isset($v['installments'],$v['period'])&&$r['amountMinor']!==0)$r['installments']=Rules::installments($r['amountMinor'],(int)$v['installments'],$v['period']);
        $r['status']=['approve'=>'approved','reject'=>'rejected','cancel'=>'cancelled'][$v['decision']];$r['decisionNote']=$v['note'];$r['approvedBy']=$a->name;$r['approvedAt']=now()->toIso8601String();
        R19Store::put('r29:settlement:'.$r['id'],$r,$r['_version']);Audit::record($a,'hr','r29-settlement',$r['id'],$v['decision'],$old,$r,$tx);
    }
    public static function html(User $a,string $id): string {
        $s=R19Store::get('r29:settlement:'.$id);abort_unless($s&&HrWorkflow::canReadLinked($a,'r29-settlement',$id),404);
        $e=fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');$scale=$s['currency']==='JOD'?3:2;$amt=fn($n)=>number_format($n/(10**$scale),$scale,'.',',').' '.$e($s['currency']);$rows='';
        foreach($s['installments']as$i)$rows.='<tr><td>'.$e($i['index']).'</td><td>'.$e($i['period']).'</td><td>'.$amt($i['amountMinor']).'</td><td>'.(!empty($i['hoursId'])?'مرتبط بسجل ساعات راتب':'لم يربط بساعات راتب').'</td></tr>';
        $lines='';foreach($s['calculation']['lines']??[]as$i)$lines.='<tr><td>'.$e($i['label']).'</td><td>'.($i['direction']==='debit'?'استقطاع':'استحقاق').'</td><td>'.$amt($i['amountMinor']).'</td></tr>';
        foreach($s['calculation']['months']??[]as$i)$lines.='<tr><td>'.$e($i['from'].' — '.$i['to']).'</td><td>'.$e($i['days'].' / '.$i['divisor']).'</td><td>'.$amt($i['amountMinor']).'</td></tr>';
        return '<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><title>'.$e($s['reference']).'</title><style>body{font:16px/1.9 Arial;margin:30px;color:#172230}h1{font-size:25px}table{border-collapse:collapse;width:100%;margin:24px 0}td,th{border-bottom:1px solid #ccd3db;padding:10px;text-align:right}.note{padding:15px;background:#f3f5f7}@media print{@page{size:A4;margin:18mm}body{margin:0}}</style><h1>بيان تسوية موظف — موش</h1><p>'.$e($s['employeeName']).' · '.$e($s['reference']).'</p><p>تاريخ الإنشاء: '.$e(substr($s['createdAt'],0,10)).' · الحالة: '.$e(['approved'=>'معتمد','pending_hr'=>'بانتظار الموارد البشرية','rejected'=>'مرفوض','cancelled'=>'ملغى'][$s['status']]??$s['status']).'</p><p>'.$e($s['reason']).'</p><h2>صافي التسوية: '.$amt($s['amountMinor']).'</h2><table><thead><tr><th>البند / الفترة</th><th>الأساس</th><th>القيمة</th></tr></thead><tbody>'.$lines.'</tbody></table><h2>جدول التطبيق</h2><table><thead><tr><th>القسط</th><th>الشهر</th><th>القيمة</th><th>الربط</th></tr></thead><tbody>'.$rows.'</tbody></table><p>اعتماد: '.$e($s['approvedBy']??'لم يعتمد').'</p><div class="note">هذا بيان حسابي وليس إيصال صرف أو مخالصة موقعة. تسوية نهاية الخدمة حسب البنود والأساس المدخلين من الموارد البشرية، ولا يتضمن افتراض معادلة قانونية. يرجع إلى إثبات الصرف والمخالصة الموقعة المرتبطين بالملف.</div></html>';
    }
    /** Called under the same business lock as the payroll-hours transaction. */
    public static function apply(array &$h,?array $old): void {
        $items=[];$credit=0;$debit=0;
        if($old&&($old['status']??'draft')!=='draft'){
            $items=$old['calculation']['r29Adjustments']??[];
        }else{
            foreach(R19Store::documents('r29:settlement:')as$s){if(($s['status']??'')!=='approved'||($s['employeeId']??'')!==(string)($h['employeeId']??'')||($s['currency']??'')!==($h['currency']??''))continue;
                foreach($s['installments']as$i)if(($i['period']??'9999')<=($h['period']??'0000')&&(empty($i['hoursId'])||$i['hoursId']===(string)($h['id']??'')))
                    $items[]=['settlementId'=>$s['id'],'index'=>$i['index'],'amountMinor'=>$i['amountMinor'],'reference'=>$s['reference']];}
        }
        foreach($items as$i){if($i['amountMinor']>=0)$credit+=$i['amountMinor'];else$debit-= $i['amountMinor'];}
        if(!$items)return;
        $h['calculation']['r29Adjustments']=$items;$h['calculation']['r29Credit']=$credit;$h['calculation']['r29Debit']=$debit;
        $h['calculation']['gross']+=$credit;$h['calculation']['deductions']+=$debit;$h['calculation']['net']+=$credit-$debit;
        abort_unless($h['calculation']['net']>=0&&$h['calculation']['gross']<90000000000000,422,'أقساط التسوية تتجاوز صافي الراتب. عدّل خطة الأقساط من HR قبل الاعتماد.');
    }
    public static function afterHours(array $h,?array $old): void {
        $approve=($h['status']??'')==='approved'&&($old['status']??'')!=='approved';$release=($h['status']??'')==='superseded'&&($old['status']??'')==='approved';if(!$approve&&!$release)return;
        foreach($h['calculation']['r29Adjustments']??[]as$item){$s=R19Store::get('r29:settlement:'.$item['settlementId']);abort_unless($s&&$s['status']==='approved',409,'تغير اعتماد التسوية.');
            foreach($s['installments']as&$i)if((int)$i['index']===(int)$item['index']){
                if($approve){abort_unless(empty($i['hoursId'])||$i['hoursId']===(string)$h['id'],409,'قسط التسوية مستخدم بسجل راتب آخر.');abort_unless($i['amountMinor']===$item['amountMinor'],409);$i['hoursId']=(string)$h['id'];$i['appliedPeriod']=$h['period'];}
                elseif(($i['hoursId']??'')===(string)$h['id']){$i['hoursId']=null;unset($i['appliedPeriod']);}break;
            }unset($i);R19Store::put('r29:settlement:'.$s['id'],$s,$s['_version']);
        }
    }
}

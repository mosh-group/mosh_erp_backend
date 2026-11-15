<?php
namespace App\Support;
use App\Models\{User,Invoice,FinancialDocument,Payment,CashAccount,JournalEntry,Setting,SupportRequest,HrRequest,Employee,PayrollHours,PayrollRun,Task};
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
class FinanceRules
{
    private static function need(bool $ok,string $message): void { BusinessRules::need($ok,$message); }
    public static function documents(): array {
        $rows=FinancialDocument::all()->map(fn($m)=>$m->document())->all();
        foreach (Invoice::all() as $i) if (!empty($i->data['finance21'])) $rows[]=array_merge($i->data['finance21'],['id'=>'INV:'.$i->id,'number'=>$i->data['number']??'','kind'=>'customer','party'=>$i->data['customer'],'sourceLabel'=>$i->data['source']??$i->data['finance21']['sourceLabel']??'']);
        return $rows;
    }
    public static function document(string $id): ?array { foreach (self::documents() as $d) if ((string)$d['id']===$id) return $d; return null; }
    public static function activePayments(): array { return Payment::all()->filter(fn($m)=>empty($m->data['reversedAt'])&&$m->data['kind']!=='reversal')->map(fn($m)=>$m->document())->all(); }
    public static function remaining(array $d): int { return $d['total']-array_sum(array_map(fn($p)=>($p['docId']??'')===$d['id']?$p['amount']:0,self::activePayments())); }
    public static function lockDate(string $date): void {
        Validator::make(['date'=>$date],['date'=>'required|date_format:Y-m-d'])->validate();
        $locked=Setting::valueOf('finance')['lockedThrough']??''; self::need(!$locked||$date>$locked,'الفترة المالية مقفلة.');
    }
    private static function integer(mixed $n,bool $positive=false): void { self::need(is_int($n)||is_numeric($n)&&(float)$n===(float)(int)$n,'المبلغ الداخلي يجب أن يكون عدداً صحيحاً بوحدات العملة الصغرى.');self::need($n>=($positive?1:0)&&$n<9e13,'مبلغ غير صالح.'); }
    public static function validate(User $u,string $res,?array $old,array &$d): void {
        if ($res==='invoices'||$res==='financial-documents') {
            if ($res==='invoices') {
                if (empty($d['finance21'])) {
                    self::need(empty($old['finance21']),'لا يمكن إزالة البيانات المالية.');
                    $source=\App\Models\Quotation::whereKey((string)($d['source']??''))->orWhere('data->number',(string)($d['source']??''))->first();
                    self::need($source&&($source->data['status']??'')==='order','بيانات finance21 أو عرض معتمد مطلوب.');
                    $lines=[];foreach ($source->data['lines']??[] as $line) if (($line['type']??'product')==='product') $lines[]=array_merge($line,['name'=>$line['product']??'']);
                    $date=now()->toDateString();$d['finance21']=['status'=>'draft','currency'=>$d['currency']??$source->data['currency']??'USD','date'=>$date,'dueDate'=>$date,'lines'=>$lines,'sourceId'=>$source->id];
                }
                if (!empty($d['source'])) {
                    $source=\App\Models\Quotation::whereKey((string)$d['source'])->orWhere('data->number',$d['source'])->first();
                    self::need($source&&($source->data['status']??'')==='order','مرجع العرض غير صالح.');
                    self::need(empty($d['finance21']['sourceId'])||(string)$d['finance21']['sourceId']===(string)$source->id,'مرجعا العرض مختلفان.');$d['finance21']['sourceId']=$source->id;
                }
                $f=array_merge($d['finance21'],['id'=>'INV:'.$d['id'],'kind'=>'customer','party'=>$d['customer'],'number'=>$d['number']??'']);
                self::doc($u,$old['finance21']??null,$f);
                $d['finance21']=$f; $d['currency']=$f['currency']; $d['total']=$f['total']/(10**($f['currency']==='JOD'?3:2));
            } else {self::need(in_array($d['kind']??'',['vendor','employee','expense'],true),'نوع مستند مالي غير صالح.');self::doc($u,$old,$d);}
        }
        if ($res==='cash-accounts') {
            abort_unless($u->administration(),403); Money::currency($d['currency']); self::lockDate($d['openingDate']);
            self::need($d['openingDate']<=now()->toDateString(),'رصيد افتتاحي بتاريخ مستقبلي.');self::integer($d['opening']??0);
            self::need(in_array($d['type'],['cash','bank'],true),'نوع حساب غير صالح.');
            self::need(!CashAccount::where('id','!=',(string)$d['id'])->where('data->name',$d['name'])->where('data->currency',$d['currency'])->exists(),'الحساب مسجل مسبقاً.');
            if ($old) foreach (['currency','opening','openingDate','type'] as $k) self::need(($old[$k]??null)===($d[$k]??null),'رصيد الحساب الافتتاحي لا يعدل.');
        }
        if ($res==='payments') self::payment($u,$old,$d);
        if ($res==='journal-entries') JournalRules::validate($u,$old,$d);
        if ($res==='payroll-hours') self::hours($u,$old,$d);
        if ($res==='payroll-runs') self::run($u,$old,$d);
    }
    private static function doc(User $u,?array $old,array &$d): void {
        Validator::make($d,['currency'=>'required|string','date'=>'required|date_format:Y-m-d','kind'=>'required|in:customer,vendor,employee,expense','lines'=>'sometimes|array','party'=>'required|string'])->validate();
        Money::currency($d['currency']); self::lockDate($d['date']);
        Validator::make($d,['dueDate'=>'required|date_format:Y-m-d|after_or_equal:date','kind'=>'required|in:customer,vendor,employee,expense','status'=>'required|in:draft,review,posted,cancelled'])->validate();
        if (!empty($d['opening22'])) { abort_unless($u->administration(),403); self::integer($d['total'],true); $d['net']=$d['total'];$d['tax']=0; }
        elseif (in_array($d['kind'],['customer','vendor'],true)) $d=array_merge($d,Money::totals($d['lines']??[],$d['currency']));
        else { self::integer($d['total'],true); $d['net']=$d['total']; $d['tax']=0; }
        $status=$d['status'];$previous=$old['status']??'draft';
        if (!$old) self::need($status==='draft'||!empty($d['opening22']),'احفظ المسودة قبل اعتمادها.');
        if ($old&&$previous!=='draft') foreach (['kind','party','partyId','sourceId','currency','date','dueDate','lines','total','net','tax','category','period','opening22','payroll23'] as $key) self::need(($old[$key]??null)===($d[$key]??null),'المستند المعتمد أو قيد المراجعة لا يعدّل.');
        if ($status!==$previous) {
            self::need(in_array($status,['draft'=>['review','posted','cancelled'],'review'=>['posted','draft','cancelled'],'posted'=>['cancelled'],'cancelled'=>[]][$previous]??[],true),'انتقال حالة مستند غير صالح.');
            if ($status==='posted') {
                self::need($d['date']<=now()->toDateString(),'لا يمكن ترحيل تاريخ مستقبلي.');
                $needsAdmin=$previous==='review'||$d['kind']==='expense'||$d['kind']==='employee'&&empty($d['sourceId'])||$d['kind']==='vendor'&&empty($d['sourceId']);
                if ($needsAdmin) { abort_unless($u->administration(),403); self::need($previous==='review'||!empty($d['opening22']),'أرسل المستند للإدارة أولاً.'); }
                $d['postedAt']=now()->toIso8601String(); $d['postedBy']=$u->name;
            }
            if ($previous==='review') { abort_unless($u->administration(),403); self::need(!empty($d['decisionReason']),'اكتب ملاحظة القرار.'); $d['approvedBy']=$u->name;$d['approvedAt']=now()->toIso8601String(); }
            if ($status==='cancelled') {
                self::need(!empty($d['cancelReason']),'سبب الإلغاء مطلوب.');
                if ($previous==='posted') abort_unless($u->administration(),403);
                self::need(!collect(self::activePayments())->contains(fn($p)=>($p['docId']??'')===$d['id']),'اعكس التسديد قبل الإلغاء.');
                self::need(!Task::where('data->finance21InvoiceId',$d['id'])->where('status','archived_invoiced')->exists(),'الفاتورة مرتبطة بمهمة مؤرشفة.');
                $d['cancelledAt']=now()->toIso8601String();$d['cancelledBy']=$u->name;
            }
        }
        if ($d['kind']==='vendor') {
            self::need(\App\Models\Supplier::whereKey($d['partyId']??'')->exists(),'حدد المورد.');
            self::need(!FinancialDocument::where('id','!=',(string)$d['id'])->where('data->kind','vendor')->where('data->partyId',$d['partyId'])->where('data->reference',$d['reference'])->where('data->status','!=','cancelled')->exists(),'فاتورة المورد مسجلة مسبقاً.');
            if (!empty($d['sourceId'])) self::order($d,$status==='posted'); else self::need(!empty($d['standalone22'])||!empty($d['opening22']),'مرجع أمر الشراء مطلوب.');
        }
        if ($d['kind']==='employee') {
            self::need(Employee::whereKey($d['partyId']??'')->exists(),'حدد الموظف من ملف الموارد البشرية.');
            if (!empty($d['sourceId'])) {
                $r=HrRequest::find($d['sourceId'])?->data;
                self::need((bool)$r&&($r['status']==='approved'||$r['status']==='pending'&&($r['route'][$r['stageIndex']]??'')==='المحاسبة'),'طلب HR ليس جاهزاً للصرف.');
                self::need((string)$r['employeeId']===(string)$d['partyId']&&$r['type']===$d['category'],'طلب HR لا يطابق المستند.');
                self::need(Money::minor($r['data']['amount']??0,$d['currency'])===$d['total'],'المبلغ يختلف عن طلب HR.');
                self::need(empty($r['data']['currency'])||$r['data']['currency']===$d['currency'],'عملة طلب HR مختلفة.');
                self::need(!FinancialDocument::where('id','!=',(string)$d['id'])->where('data->sourceId',$d['sourceId'])->where('data->status','!=','cancelled')->exists(),'طلب HR مرتبط بمستند سابق.');
            } elseif (empty($d['opening22'])) {
                Validator::make($d,['period'=>['required','regex:/^\d{4}-(0[1-9]|1[0-2])$/']])->validate();
                if (in_array($d['category']??'',['salary','settlement'],true)) self::need(!FinancialDocument::where('id','!=',(string)$d['id'])->where('data->kind','employee')->where('data->partyId',$d['partyId'])->where('data->period',$d['period'])->where('data->category',$d['category'])->where('data->status','!=','cancelled')->exists(),'يوجد كشف لهذا الموظف والشهر.');
            }
        }
        if ($d['kind']==='customer'&&!empty($d['sourceId'])) {
            $q=\App\Models\Quotation::find($d['sourceId']); self::need(($q?->data['status']??'')==='order','اعتمد عرض السعر أولاً.');
            self::need(($q->data['customer']??'')===$d['party'],'عميل الفاتورة مختلف.');
            foreach (self::documents() as $other) if ($other['id']!==$d['id']&&$other['kind']==='customer'&&($other['sourceId']??'')===$d['sourceId']) self::need($other['status']==='cancelled','يوجد مستند لهذا العرض.');
        }
    }
    private static function order(array $d,bool $received): void {
        $r=SupportRequest::find($d['sourceId'])?->data; $m=$r['operation18']??[];$q=$m['approval']['quote']??null;
        self::need((bool)$q&&!in_array($m['phase']??'',['cancelled','returned','review','draft'],true),'أمر الشراء غير معتمد.');
        self::need(($d['approvalId']??'')===($m['approval']['id']??'')&&$d['currency']===$q['currency']&&(string)$d['partyId']===(string)$q['supplierId'],'الفاتورة لا تطابق اعتماد الشراء.');
        $others=collect(self::documents())->filter(fn($x)=>$x['id']!==$d['id']&&$x['kind']==='vendor'&&($x['sourceId']??'')===$d['sourceId']&&$x['status']==='posted');
        $cost=Money::round(bcmul((string)$m['approval']['total']['total'],(string)(10**($d['currency']==='JOD'?3:2)),8));
        self::need($others->sum('total')+$d['total']<=$cost,'الفواتير تتجاوز قيمة الأمر.');
        foreach ($d['lines']??[] as $l) {
            if (!empty($l['shipping'])) continue;
            $line=collect($q['lines'])->firstWhere('lineId',$l['lineId']??''); self::need((bool)$line,'البند غير موجود بالاعتماد.');
            $prior=$others->flatMap(fn($x)=>$x['lines'])->where('lineId',$l['lineId'])->sum('qty');
            self::need($prior+$l['qty']<=$line['qty']+1e-8,'الكمية المفوترة تتجاوز المعتمد.');
            if ($received) self::need($prior+$l['qty']<=collect($m['receipts']??[])->flatMap(fn($x)=>$x['lines'])->where('lineId',$l['lineId'])->sum('qty')+1e-8,'سجل استلام الشراء قبل اعتماد الفاتورة.');
        }
    }
    private static function payment(User $u,?array $old,array &$p): void {
        Money::currency($p['currency']);self::integer($p['amount'],true);self::lockDate($p['date']);self::need($p['date']<=now()->toDateString(),'حركة نقدية بتاريخ مستقبلي.');
        if ($old) {
            self::need(empty($old['reversedAt'])&&!empty($p['reversedAt']),'السند لا يعدل؛ استخدم العكس.');
            foreach ($old as $key=>$v) if (!in_array($key,['reversedAt','reversedBy','reverseReason','updatedAt','updatedBy','_version'],true)) self::need(($p[$key]??null)===$v,'لا يمكن تغيير السند.');
            self::need(!empty($p['reverseReason']),'سبب العكس مطلوب.');
            self::need(!collect(self::activePayments())->contains(fn($x)=>($x['advanceId']??'')===$p['id']),'اعكس التسويات المرتبطة أولاً.');
            $p['reversedAt']=now()->toIso8601String();$p['reversedBy']=$u->name; return;
        }
        $kind=$p['kind']; self::need(in_array($kind,['receipt','payout','supplier_advance','transfer','apply_advance','employee_return','employee_offset','reversal'],true),'نوع سند غير صالح.');
        if ($kind==='reversal') {
            $orig=Payment::find($p['reverses']??'')?->data;self::need((bool)$orig&&$orig['kind']!=='reversal'&&!empty($orig['reversedAt']),'سند أصل صالح للعكس مطلوب.');
            self::need($p['amount']===$orig['amount']&&$p['currency']===$orig['currency'],'مبلغ أو عملة العكس مختلفة.');
            self::need(!Payment::where('data->reverses',$p['reverses'])->exists(),'السند معكوس مسبقاً.'); return;
        }
        if (!empty($p['token'])) self::need(!Payment::where('data->token',$p['token'])->exists(),'العملية مسجلة مسبقاً.');
        self::need(!collect(self::activePayments())->contains(fn($x)=>$x['kind']===$kind&&($x['accountId']??'')===($p['accountId']??'')&&($x['docId']??'')===($p['docId']??'')&&($x['sourceId']??'')===($p['sourceId']??'')&&$x['reference']===$p['reference']),'مرجع السند مسجل مسبقاً.');
        $d=self::document($p['docId']??'');
        if (in_array($kind,['receipt','payout','apply_advance','employee_offset'],true)) {
            self::need((bool)$d&&$d['status']==='posted','اعتمد المستند أولاً.');
            self::need($p['currency']===$d['currency']&&$p['date']>=$d['date'],'عملة أو تاريخ التسديد غير صالح.');
            self::need($p['amount']<=self::remaining($d),'المبلغ يتجاوز المتبقي.');
            self::need($kind==='receipt'?$d['kind']==='customer':$d['kind']!=='customer','اتجاه السند غير صحيح.');
            $p['party']=$d['party'];$p['partyId']=$d['partyId']??'';$p['sourceId']=$d['sourceId']??'';
            if ($kind==='payout'&&$d['kind']==='vendor') foreach (self::activePayments() as $x) if ($x['kind']==='supplier_advance'&&($x['sourceId']??'')===($d['sourceId']??'')) self::need(self::advanceLeft($x['id'])===0,'سوّ الدفعة المقدمة قبل صرف مبلغ إضافي.');
        }
        if ($kind==='supplier_advance') {
            $m=SupportRequest::find($p['sourceId']??'')?->data['operation18']??[];$q=$m['approval']['quote']??null;
            self::need((bool)$q&&!in_array($m['phase'],['cancelled','returned','review','draft'],true),'حدد أمر شراء معتمداً.');
            self::need($q['currency']===$p['currency']&&!empty($p['note']),'عملة أو شرح دفعة مقدمة غير صالح.');
            $spent=collect(self::activePayments())->filter(fn($x)=>in_array($x['kind'],['payout','supplier_advance'],true)&&($x['sourceId']??'')===$p['sourceId'])->sum('amount');
            self::need($spent+$p['amount']<=Money::round(bcmul((string)$m['approval']['total']['total'],(string)(10**($p['currency']==='JOD'?3:2)),8)),'الدفعات تتجاوز أمر الشراء.');
            $p['party']=$q['supplierName'];$p['partyId']=$q['supplierId'];$p['approvalId']=$m['approval']['id'];
        }
        if (in_array($kind,['apply_advance','employee_return','employee_offset'],true)) {
            $advance=Payment::find($p['advanceId']??'')?->document();
            self::need((bool)$advance&&empty($advance['reversedAt'])&&$advance['currency']===$p['currency']&&$p['date']>=$advance['date']&&$p['amount']<=self::advanceLeft($advance['id']),'السلفة أو رصيدها أو تاريخها غير صالح.');
            $ad=self::document($advance['docId']??'');
            if ($kind==='apply_advance') self::need($d['kind']==='vendor'&&$advance['kind']==='supplier_advance'&&($advance['sourceId']??'')===($d['sourceId']??''),'الدفعة لا تخص أمر الشراء.');
            else {
                self::need(($ad['kind']??'')==='employee'&&($ad['category']??'')==='advance','حدد سلفة موظف مصروفة.');
                if ($kind==='employee_offset') self::need($d['kind']==='employee'&&in_array($d['category'],['salary','settlement'],true)&&$d['partyId']===$ad['partyId']&&!empty($p['note']),'التسوية لا تخص الموظف أو ينقصها تأكيد عدم الخصم المسبق.');
                $p['party']=$ad['party'];$p['partyId']=$ad['partyId'];
            }
        }
        if (in_array($kind,['apply_advance','employee_offset'],true)) $p['accountId']='';
        else self::cashAccount($p['accountId']??'',$p['currency'],$p['date']);
        if ($kind==='transfer') { self::need(($p['accountId']??'')!==($p['toAccountId']??''),'اختر حسابين مختلفين.');self::cashAccount($p['toAccountId']??'',$p['currency'],$p['date']); }
        $p['at']=now()->toIso8601String();$p['by']=$u->name;
    }
    public static function advanceLeft(string $id): int {
        $p=Payment::find($id)?->data;if (!$p||!empty($p['reversedAt'])) return 0;
        return $p['amount']-collect(self::activePayments())->where('advanceId',$id)->sum('amount');
    }
    private static function cashAccount(string $id,string $currency,string $date): void {
        $a=CashAccount::find($id)?->data;self::need((bool)$a&&($a['active']??true)&&$a['currency']===$currency&&$date>=$a['openingDate'],'حدد حساباً فعالاً بنفس العملة وبتاريخ صحيح.');
    }
    private static function hours(User $u,?array $old,array &$h): void {
        foreach (['baseAmount','normHours','regularHours','paidHours','overtimeHours','overtimeRate','bonus','deductions'] as $key) if(isset($h[$key]))$h[$key]=Money::digits($h[$key]);
        Validator::make($h,['period'=>['required','regex:/^\d{4}-(0[1-9]|1[0-2])$/'],'basis'=>'required|in:monthly,hourly','normHours'=>'required|numeric|gt:0',
            'regularHours'=>'required|numeric|min:0','paidHours'=>'required|numeric|min:0','overtimeHours'=>'required|numeric|min:0','overtimeRate'=>'required|numeric|between:1,5'])->validate();
        Validator::make($h,['status'=>'required|in:draft,approved,superseded','regularHours'=>'numeric|between:0,1000','paidHours'=>'numeric|between:0,1000','overtimeHours'=>'numeric|between:0,1000','normHours'=>'numeric|gt:0|max:1000'])->validate();
        if (!$old) self::need($h['status']==='draft','ابدأ الساعات بمسودة.');
        if ($old) self::need($h['status']===$old['status']||in_array($h['status'],['draft'=>['approved'],'approved'=>['superseded'],'superseded'=>[]][$old['status']],true),'انتقال حالة ساعات غير صالح.');
        if ($old&&$old['status']!=='draft') foreach ($old as $k=>$v) if (!in_array($k,['status','supersededAt','updatedAt','updatedBy','_version'],true)) self::need(($h[$k]??null)===$v,'الساعات المعتمدة لا تعدل.');
        if (($old['status']??'')==='approved'&&$h['status']==='superseded') {
            self::need(!FinancialDocument::where('data->kind','employee')->where('data->category','salary')->where('data->partyId',$h['employeeId'])->where('data->period',$h['period'])->where('data->status','!=','cancelled')->exists(),'الساعات مستخدمة بمستند راتب فعال.');
            $h['supersededAt']=now()->toIso8601String();
        }
        $norm=(float)$h['normHours'];$regular=(float)$h['regularHours'];$paid=(float)$h['paidHours'];$ot=(float)$h['overtimeHours'];
        self::need($regular+$paid<=$norm+1e-6,'الساعات تتجاوز المتعاقد عليه.');
        $base=Money::minor($h['baseAmount'],$h['currency']);$bonus=Money::minor($h['bonus']??0,$h['currency']);$deduct=Money::minor($h['deductions']??0,$h['currency']);
        self::need($base>0,'حدد الأجر.');
        $p=Employee::find($h['employeeId']);self::need((bool)$p,'الموظف غير موجود.');
        if ($ot>0) self::need(empty($p->data['isManager'])&&!empty($h['overtimeApproval']),'الإضافي يحتاج مرجع موافقة ولا يحتسب للمدراء.');
        if ($bonus) self::need(!empty($h['bonusReason']),'مرجع المكافأة مطلوب.');if ($deduct) self::need(!empty($h['deductionReason']),'سبب الاستقطاع مطلوب.');
        $den=$h['basis']==='monthly'?(string)$norm:'1';
        $regularPay=Money::round(bcdiv(bcmul((string)$base,(string)($regular+$paid),16),$den,16));
        $overtimePay=Money::round(bcdiv(bcmul(bcmul((string)$base,(string)$ot,16),(string)$h['overtimeRate'],16),$den,16));
        $gross=$regularPay+$overtimePay+$bonus;self::need($gross>=$deduct&&$gross<9e13,'صافي أو إجمالي أجر غير صالح.');
        $h['calculation']=['norm'=>$norm,'regular'=>$regular,'paid'=>$paid,'ot'=>$ot,'base'=>$base,'regularPay'=>$regularPay,'overtimePay'=>$overtimePay,'bonus'=>$bonus,'deductions'=>$deduct,'gross'=>$gross,'net'=>$gross-$deduct,'shortfall'=>max(0,$norm-$regular-$paid),'currency'=>$h['currency']];
        \App\Services\R29\PayrollAdjustments::apply($h,$old);
        if (($h['status']??'draft')==='approved'&&($old['status']??'')!=='approved') {
            self::need(!FinancialDocument::where('data->kind','employee')->where('data->category','salary')->where('data->partyId',$h['employeeId'])->where('data->period',$h['period'])->where('data->status','!=','cancelled')->exists(),'يوجد كشف راتب لهذا الموظف والشهر.');
            $h['approvedAt']=now()->toIso8601String();$h['approvedBy']=$u->name;$h['fingerprint']=json_encode($h['calculation'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        }
    }
    private static function run(User $u,?array $old,array &$r): void {
        Validator::make($r,['period'=>['required','regex:/^\d{4}-(0[1-9]|1[0-2])$/'],'date'=>'required|date_format:Y-m-d|before_or_equal:today','dueDate'=>'required|date_format:Y-m-d|after_or_equal:date','lines'=>'required|array|min:1'])->validate();
        Money::currency($r['currency']);self::lockDate($r['date']);
        self::need($r['date']>=\Carbon\Carbon::parse($r['period'].'-01')->endOfMonth()->toDateString(),'تاريخ الكشف يسبق نهاية الشهر.');
        self::need(!PayrollRun::where('id','!=',(string)$r['id'])->where('data->period',$r['period'])->where('data->currency',$r['currency'])->where('data->status','!=','cancelled')->exists(),'يوجد كشف للشهر والعملة.');
        if ($old&&($old['status']??'')!==($r['status']??'')&&in_array($r['status'],['posted','draft','cancelled'],true)) { abort_unless($u->administration()||$old['status']==='draft',403); }
        Validator::make($r,['status'=>'required|in:draft,review,posted,cancelled','lines.*.employeeId'=>'required','lines.*.hoursId'=>'required','lines.*.docId'=>'required'])->validate();
        if (!$old) self::need($r['status']==='draft','ابدأ كشف الرواتب بمسودة.');
        if ($old) {
            foreach (['period','currency','date','dueDate','lines'] as $key) self::need(($r[$key]??null)===($old[$key]??null),'محتوى كشف الرواتب محفوظ من الساعات المعتمدة.');
            self::need($r['status']===$old['status']||in_array($r['status'],['draft'=>['review','cancelled'],'review'=>['posted','draft','cancelled'],'posted'=>['cancelled'],'cancelled'=>[]][$old['status']],true),'انتقال حالة كشف غير صالح.');
        }
        foreach ($r['lines'] as $line) {
            $h=PayrollHours::find($line['hoursId']??'')?->data;self::need((bool)$h&&$h['status']==='approved'&&$h['period']===$r['period']&&$h['currency']===$r['currency'],'ساعات معتمدة مطابقة مطلوبة.');
        }
    }
    public static function afterTransaction(User $u,array &$changes,string $tx): void {
        $payments=Payment::all()->mapWithKeys(fn($m)=>[$m->id=>$m->document()])->all();
        foreach ($payments as $p) if (!empty($p['reversedAt'])&&$p['kind']!=='reversal') self::need(collect($payments)->contains(fn($x)=>($x['reverses']??'')===$p['id']),'يجب إنشاء سند العكس مع الأصل في معاملة واحدة.');
        foreach (CashAccount::all() as $a) {
            $cash=$a->data['opening']??0;$moves=[];
            foreach ($payments as $p) {
                $base=$p['kind']==='reversal'?($payments[$p['reverses']]??null):$p; self::need((bool)$base,'سند عكس دون أصل.');
                if (in_array($base['kind'],['apply_advance','employee_offset'],true)) continue;
                $sign=$p['kind']==='reversal'?-1:1;$delta=0;
                if (($base['accountId']??'')===$a->id) $delta=(in_array($base['kind'],['receipt','employee_return'],true)?1:-1)*$base['amount']*$sign;
                if (($base['toAccountId']??'')===$a->id&&$base['kind']==='transfer') $delta+=$base['amount']*$sign;
                if ($delta) $moves[]=['date'=>$p['date'],'at'=>$p['at']??'','delta'=>$delta];
            }
            usort($moves,fn($x,$y)=>strcmp($x['date'],$y['date'])?:strcmp($x['at'],$y['at']));
            foreach ($moves as $m) { self::need($m['date']>=$a->data['openingDate'],'حركة تسبق الافتتاحي.');$cash+=$m['delta'];self::need($cash>=0,'الرصيد النقدي غير كافٍ في تاريخ العملية.'); }
        }
        foreach (self::documents() as $d) self::need(self::remaining($d)>=0,'المبلغ المسدد يتجاوز المستند.');
        foreach (PayrollRun::all() as $run) {
            $r=$run->data;$seen=[];
            foreach ($r['lines'] as $line) {
                $h=PayrollHours::find($line['hoursId'])?->data;$doc=FinancialDocument::find($line['docId'])?->data;
                self::need($h&&$doc&&!in_array((string)$line['employeeId'],$seen,true),'سطر كشف رواتب غير صالح أو مكرر.');$seen[]=(string)$line['employeeId'];
                self::need((string)$line['employeeId']===(string)$h['employeeId']&&(string)$doc['partyId']===(string)$h['employeeId'],'الموظف لا يطابق الساعات والكشف.');
                self::need(($doc['payroll23']['runId']??'')===$run->id&&($doc['payroll23']['hoursId']??'')===$line['hoursId'],'المستند لا يطابق كشف الرواتب.');
                self::need($doc['total']===$h['calculation']['net']&&$doc['currency']===$r['currency']&&$doc['period']===$r['period']&&$doc['status']===$r['status'],'مبلغ أو حالة كشف الرواتب لا تطابق المستند.');
                self::need($r['status']==='cancelled'||$h['status']==='approved','الساعات ليست معتمدة لكشف فعال.');
                self::need(is_string($h['fingerprint']??null)&&json_decode($h['fingerprint'],true)==$h['calculation'],'بصمة الساعات المعتمدة غير صالحة.');
                $snapshot=$doc['payroll23']['snapshot']??[];
                self::need(is_array($snapshot)&&($snapshot['fingerprint']??'')===$h['fingerprint'],'بصمة نسخة الراتب لا تطابق المصدر.');
                foreach (['employeeId','period','currency','basis','baseAmount','normHours','regularHours','paidHours','overtimeHours','overtimeRate','bonus','deductions','reference','source','calculation'] as $key) self::need(($snapshot[$key]??null)==($h[$key]??null),'نسخة مصدر الراتب تغيرت: '.$key);
                self::need(($line['calculation']??[])==$h['calculation']&&($doc['payroll23']['calculation']??[])==$h['calculation'],'حساب الراتب يختلف عن الساعات المعتمدة.');
            }
        }
        JournalRules::synchronize($u,$changes,$tx);
    }
}

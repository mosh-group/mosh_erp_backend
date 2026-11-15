<?php
namespace App\Support;
use App\Models\{User,JournalEntry,CashAccount,Payment,Setting,Customer,Supplier,Employee,FinancialDocument};
use Illuminate\Support\Str;
class JournalRules
{
    private static function need(bool $b,string $s): void { BusinessRules::need($b,$s); }
    public static function chart(): array {
        $defaults=json_decode(file_get_contents(resource_path('defaults.json')),true)['chart'];
        $rows=array_merge($defaults,Setting::valueOf('finance-chart'));
        foreach (CashAccount::all() as $a) $rows[]=['code'=>'CASH:'.$a->id,'name'=>$a->data['name'],'type'=>'asset','cash'=>true,'currency'=>$a->data['currency'],'partyType'=>''];
        return array_column($rows,null,'code');
    }
    private static function line(string $account,int $debit=0,int $credit=0,string $party=''): array { return compact('account','debit','credit','party')+['memo'=>'']; }
    private static function entry(string $key,string $date,string $currency,string $reference,string $memo,array $lines,array $source): array {
        $lines=array_values(array_filter($lines,fn($x)=>$x['debit']||$x['credit'])); return compact('key','date','currency','reference','memo','lines','source');
    }
    public static function fingerprint(array $e): string {
        return json_encode(['date'=>$e['date'],'currency'=>$e['currency'],'lines'=>array_map(fn($l)=>[$l['account'],$l['debit'],$l['credit'],$l['party']??''],$e['lines']),'source'=>$e['source']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }
    private static function party(array $d): string {
        $kind=$d['kind'];if ($kind==='expense') return 'expense:'.$d['id'];
        $class=['customer'=>Customer::class,'vendor'=>Supplier::class,'employee'=>Employee::class][$kind];
        $saved=Setting::valueOf('finance-links')[$d['id']]??null;
        if ($saved) { [$role,$id]=explode(':',$saved,2);self::need($role===$kind&&$class::whereKey($id)->exists(),'رابط الطرف غير صالح.');return $saved; }
        if (!empty($d['partyId'])) { self::need($class::whereKey((string)$d['partyId'])->exists(),'الطرف المالي غير موجود.');return $kind.':'.$d['partyId']; }
        $matches=$class::where('data->name',$d['party'])->get();self::need($matches->count()===1,'حدد رابطاً فريداً للطرف المالي: '.$d['party']);return $kind.':'.$matches->first()->id;
    }
    private static function reverse(array $e,string $key,string $date,string $reference,string $memo,array $source): array {
        return self::entry($key,$date,$e['currency'],$reference,$memo,array_map(fn($l)=>array_merge($l,['debit'=>$l['credit'],'credit'=>$l['debit']]),$e['lines']),$source);
    }
    public static function expected(): array {
        $entries=[];
        foreach (CashAccount::all() as $row) { $a=$row->data; if (($a['opening']??0)>0) $entries['CASH:'.$row->id]=self::entry('CASH:'.$row->id,$a['openingDate'],$a['currency'],$a['name'],'رصيد افتتاحي — '.$a['name'],[self::line('CASH:'.$row->id,$a['opening']),self::line('3000',0,$a['opening'])],['type'=>'cash','id'=>$row->id]); }
        $docs=[];
        foreach (FinanceRules::documents() as $d) {
            $docs[$d['id']]=$d;
            if (!in_array($d['status'],['posted','cancelled'],true)||$d['status']==='cancelled'&&empty($d['postedAt'])&&empty($d['approvedAt'])) continue;
            $p=self::party($d);$n=(int)$d['total'];$tax=(int)($d['tax']??0);$net=$n-$tax;$kind=$d['kind'];
            if (!empty($d['opening22'])) $lines=$kind==='customer'?[self::line('1200',$n,0,$p),self::line('3000',0,$n)]:[self::line('3000',$n),self::line($kind==='vendor'?'2100':'2200',0,$n,$p)];
            elseif ($kind==='customer') $lines=[self::line('1200',$n,0,$p),self::line('4000',0,$net),self::line('2300',0,$tax)];
            elseif ($kind==='vendor') $lines=[self::line($d['ledgerAccount22']??'1590',$net),self::line('1400',$tax),self::line('2100',0,$n,$p)];
            elseif ($kind==='employee') $lines=($d['category']??'')==='advance'?[self::line('1350',$n,0,$p),self::line('2200',0,$n,$p)]:[self::line('5100',$n),self::line('2200',0,$n,$p)];
            else $lines=[self::line('5200',$n),self::line('2250',0,$n,$p)];
            $label=!empty($d['opening22'])?'رصيد افتتاحي':(['customer'=>'فاتورة عميل','vendor'=>'فاتورة مورد','employee'=>($d['category']??'')==='advance'?'إقرار سلفة موظف':'مستحق موظف','expense'=>'مصروف معتمد'][$kind]);
            $key='DOC:'.$d['id'];
            $entries[$key]=self::entry($key,$d['date'],$d['currency'],$d['number']??'',$label.' · '.$d['party'],$lines,['type'=>'doc','id'=>$d['id'],'party'=>$p,'sourceId'=>$d['sourceId']??'','reference'=>$d['reference']??$d['sourceLabel']??'','dueDate'=>$d['dueDate']??'']);
            if ($d['status']==='cancelled') { $key='CANCEL:'.$d['id'];$entries[$key]=self::reverse($entries['DOC:'.$d['id']],$key,substr($d['cancelledAt'],0,10),'إلغاء '.($d['number']??''),$d['cancelReason']??'إلغاء مستند',['type'=>'doc','id'=>$d['id'],'party'=>$p,'reversalOf'=>'DOC:'.$d['id']]); }
        }
        $payments=Payment::all()->mapWithKeys(fn($r)=>[$r->id=>$r->document()])->all();
        foreach ($payments as $p) {
            if ($p['kind']==='reversal') continue;
            $d=$docs[$p['docId']??'']??null;$party=$d?self::party($d):'';$n=$p['amount'];$cash='CASH:'.($p['accountId']??'');$kind=$p['kind'];
            if ($kind==='receipt') $lines=[self::line($cash,$n),self::line('1200',0,$n,$party)];
            elseif ($kind==='payout') $lines=[self::line(['vendor'=>'2100','employee'=>'2200','expense'=>'2250'][$d['kind']],$n,0,$party),self::line($cash,0,$n)];
            elseif ($kind==='supplier_advance') { $party='vendor:'.$p['partyId'];$lines=[self::line('1300',$n,0,$party),self::line($cash,0,$n)]; }
            elseif ($kind==='apply_advance') $lines=[self::line('2100',$n,0,$party),self::line('1300',0,$n,$party)];
            elseif ($kind==='employee_return') { $advance=$payments[$p['advanceId']];$party=self::party($docs[$advance['docId']]);$lines=[self::line($cash,$n),self::line('1350',0,$n,$party)]; }
            elseif ($kind==='employee_offset') $lines=[self::line('2200',$n,0,$party),self::line('1350',0,$n,$party)];
            else $lines=[self::line('CASH:'.$p['toAccountId'],$n),self::line($cash,0,$n)];
            $label=['receipt'=>'سند قبض','payout'=>'سند صرف','supplier_advance'=>'دفعة مقدمة للمورد','apply_advance'=>'تسوية مقدم — دون حركة نقدية','employee_return'=>'رد سلفة موظف','employee_offset'=>'تسوية سلفة من المستحق','transfer'=>'تحويل بين الحسابات'][$kind];
            $key='PAY:'.$p['id'];$entries[$key]=self::entry($key,$p['date'],$p['currency'],$p['number']??'',$label.(!empty($p['party'])?' · '.$p['party']:''),$lines,['type'=>'payment','id'=>$p['id'],'party'=>$party,'sourceId'=>$p['sourceId']??'','docId'=>$p['docId']??'','reference'=>$p['reference']??'','advanceId'=>$p['advanceId']??'']);
        }
        foreach ($payments as $p) if ($p['kind']==='reversal') {
            $original=$entries['PAY:'.$p['reverses']]??null;self::need((bool)$original,'سند العكس بلا أصل.');$key='PAY:'.$p['id'];
            $entries[$key]=self::reverse($original,$key,$p['date'],$p['number']??'',$p['reference']??'عكس سند',['type'=>'payment','id'=>$p['id'],'party'=>$original['source']['party'],'sourceId'=>$original['source']['sourceId']??'','reversalOf'=>$original['key']]);
        }
        return $entries;
    }
    public static function validate(User $u,?array $old,array &$d): void {
        self::need(!$old,'القيد المرحّل لا يعدّل. استخدم قيداً عكسياً.');
        $d['source']??=[];
        $d['number']??='JRN-'.str_pad((string)(JournalEntry::count()+1),5,'0',STR_PAD_LEFT);
        if (!empty($d['manual'])) $d['key']=$d['key']??('MANUAL:'.$d['id']);
        if (empty($d['manual'])) {
            $expected=self::expected()[$d['key']??'']??null;self::need((bool)$expected,'القيد الآلي يحتاج مصدراً معتمداً.');
            $d=array_merge($d,$expected); // amounts and source come only from the committed business record.
        } else {
            abort_unless($u->allows('accounting','create'),403);FinanceRules::lockDate($d['date']);
            if (!empty($d['reverses'])) {
                abort_unless($u->administration(),403);$j=JournalEntry::find($d['reverses'])?->data;
                self::need((bool)$j&&!empty($j['manual'])&&empty($j['reverses'])&&!JournalEntry::where('data->reverses',$d['reverses'])->exists(),'القيد غير قابل للعكس.');
                self::need($d['currency']===$j['currency'],'عملة العكس يجب أن تطابق القيد الأصلي.');
                $d['lines']=array_map(fn($l)=>array_merge($l,['debit'=>$l['credit'],'credit'=>$l['debit']]),$j['lines']);
            }
        }
        self::check($d,!empty($d['manual']));
        self::need(!JournalEntry::where('data->key',$d['key'])->exists(),'مصدر القيد مرحل مسبقاً.');
        $d['fingerprint']=self::fingerprint($d);$d['postedAt']=now()->toIso8601String();$d['postedBy']=$u->name;
    }
    private static function check(array $e,bool $manual=false): void {
        Money::currency($e['currency']);self::need($e['date']<=now()->toDateString(),'لا يرحّل قيد بتاريخ مستقبلي.');$chart=self::chart();$dr=0;$cr=0;
        self::need(count($e['lines'])>=2,'القيد يحتاج طرفين.');
        foreach ($e['lines'] as $l) {
            $a=$chart[$l['account']]??null;self::need((bool)$a,'الحساب غير موجود بدليل الحسابات.');
            self::need(is_int($l['debit'])&&is_int($l['credit'])&&$l['debit']>=0&&$l['credit']>=0&&(($l['debit']>0&&$l['credit']===0)||($l['credit']>0&&$l['debit']===0)),'قيم المدين والدائن غير صالحة.');
            if ($manual) self::need(empty($a['cash'])&&empty($a['partyType']),'استخدم السندات لتعديل النقد والذمم.');
            if (!empty($a['partyType'])) self::need(str_starts_with($l['party']??'',$a['partyType'].':'),'نوع الطرف لا يطابق الحساب.');
            if (!empty($a['currency'])) self::need($a['currency']===$e['currency'],'عملة الحساب مختلفة.');$dr+=$l['debit'];$cr+=$l['credit'];
        }
        self::need($dr===$cr&&$dr>0&&$dr<9e13,'القيد غير متوازن.');
    }
    public static function synchronize(User $u,array &$changes,string $tx): void {
        $expected=self::expected();$existing=[];
        foreach (JournalEntry::all() as $row) {
            $d=$row->data;if (!empty($d['manual'])) continue;
            $e=$expected[$d['key']]??null;self::need((bool)$e&&self::fingerprint($e)===$d['fingerprint'],'تغير مصدر قيد مرحّل. استخدم العكس أو الإلغاء.');$existing[$d['key']]=true;
        }
        foreach ($expected as $key=>$e) if (empty($existing[$key])) {
            self::check($e);$id='JRN-'.Str::ulid();$d=$e+['id'=>$id,'number'=>'JRN-'.str_pad((string)(JournalEntry::count()+1),5,'0',STR_PAD_LEFT),
                'postedAt'=>now()->toIso8601String(),'postedBy'=>$u->name,'fingerprint'=>self::fingerprint($e)];
            $row=JournalEntry::create(['id'=>$id,'data'=>$d,'version'=>1]);$changes[]=['resource'=>'journal-entries','id'=>$id,'before'=>null,'after'=>$row->document()];
            Audit::record($u,'accounting','journal-entries',$id,'post',null,$row->document(),$tx);
        }
    }
}

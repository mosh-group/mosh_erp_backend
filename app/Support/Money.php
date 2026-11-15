<?php
namespace App\Support;
class Money
{
    public static function digits(mixed $v): string {
        BusinessRules::need(is_scalar($v),'قيمة رقمية غير صالحة.');
        return strtr(trim((string)$v),array_combine(preg_split('//u','٠١٢٣٤٥٦٧٨٩۰۱۲۳۴۵۶۷۸۹٫',-1,PREG_SPLIT_NO_EMPTY),str_split('01234567890123456789.'))+["\u{200e}"=>'',"\u{200f}"=>'',"\u{061c}"=>'']);
    }
    public static function currency(string $c): void { BusinessRules::need(in_array($c,['USD','SYP','EUR','AED','SAR','LBP','JOD','TRY','EGP'],true),'عملة غير مدعومة.'); }
    public static function minor(mixed $v,string $c): int {
        self::currency($c); BusinessRules::need(is_scalar($v),'مبلغ غير صالح.'); $s=self::digits($v); $p=$c==='JOD'?3:2;
        BusinessRules::need((bool)preg_match('/^\d+(?:\.\d{1,'.$p.'})?$/D',$s),'مبلغ غير صالح أو دقة عشرية غير مدعومة.');
        $n=bcmul($s,(string)(10**$p),0); BusinessRules::need(bccomp($n,'90000000000000',0)<0,'المبلغ أكبر من الحد المدعوم.'); return (int)$n;
    }
    public static function round(string $decimal): int { return (int)bcadd($decimal,'0.5',0); }
    public static function totals(array $lines,string $currency): array {
        self::currency($currency); $net=0;$tax=0; BusinessRules::need(count($lines)>0,'أضف بنداً واحداً على الأقل.');
        foreach ($lines as &$line) {
            BusinessRules::need(!empty($line['name'])&&preg_match('/^\d+(?:\.\d{1,4})?$/D',(string)($line['qty']??''))&&$line['qty']>0&&$line['qty']<1e9,'اسم البند والكمية الصحيحة مطلوبان.');
            $price=self::minor($line['price']??0,$currency); $discount=(string)($line['discount']??0); $rate=(string)($line['tax']??0);
            BusinessRules::need(preg_match('/^\d+(?:\.\d{1,4})?$/D',$discount)&&$discount>=0&&$discount<=100&&preg_match('/^\d+(?:\.\d{1,4})?$/D',$rate)&&$rate>=0&&$rate<=100,'الخصم والضريبة من 0 إلى 100.');
            $base=self::round(bcdiv(bcmul(bcmul((string)$price,(string)$line['qty'],16),bcsub('100',$discount,16),16),'100',16));
            $vat=self::round(bcdiv(bcmul((string)$base,$rate,16),'100',16));
            $line['net']=$base;$line['taxAmount']=$vat;$line['total']=$base+$vat; $net+=$base;$tax+=$vat;
        }
        BusinessRules::need($net+$tax>0&&$net+$tax<9e13,'إجمالي غير صالح.');
        return ['lines'=>$lines,'net'=>$net,'tax'=>$tax,'total'=>$net+$tax];
    }
}

<?php
namespace App\Services\R34;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
final class Deadline
{
    public static function calculate(string $base, string $mode, int $days = 0): string
    {
        $start=\DateTimeImmutable::createFromFormat('!Y-m-d', $base, new \DateTimeZone('UTC'));
        if (!$start || $start->format('Y-m-d')!==$base) throw new \DomainException('تاريخ البداية غير صالح.');
        if ($mode==='workdays') {
            if ($days<1 || $days>3660) throw new \DomainException('عدد أيام العمل يجب أن يكون من 1 إلى 3660.');
            $date=$start;
            for ($n=0;$n<$days;) { $date=$date->modify('+1 day'); if ($date->format('N')!=='5') $n++; }
        } elseif ($mode==='calendar_month') {
            // An explicit calendar-month preset, not an undisclosed 30 working-day period.
            $next=$start->modify('first day of next month');
            $date=$next->setDate((int)$next->format('Y'),(int)$next->format('m'),min((int)$start->format('d'),(int)$next->format('t')));
            if ($date->format('N')==='5') $date=$date->modify('+1 day');
        } else throw new \DomainException('طريقة موعد التسليم غير صالحة.');
        return $date->format('Y-m-d');
    }
    public static function today(): string { return now()->timezone(config('app.timezone','Asia/Damascus'))->format('Y-m-d'); }
    public static function preview(string $mode, int $days): array
    {
        $today=self::today();
        try { $date=self::calculate($today,$mode,$days); } catch (\DomainException $e) { abort(422,$e->getMessage()); }
        return ['baseDate'=>$today,'dueDate'=>$date,'mode'=>$mode,'days'=>$mode==='workdays'?$days:null,
            'excludedWeekdays'=>[5],'timeZone'=>config('app.timezone','Asia/Damascus'),
            'monthMeaning'=>'شهر تقويمي إلى التاريخ المقابل؛ إذا صادف الجمعة ينتقل إلى السبت'];
    }
    public static function apply(User $actor, ?array $old, array &$new): void
    {
        $before=$old['dueRule']??null; $rule=$new['dueRule']??null;
        if ($rule===$before) {
            // Editing any unrelated field never slides the deadline forward.
            if ($old && ($new['dueDate']??'')!==($old['dueDate']??'')) $new['dueRule']=null;
            return;
        }
        if ($rule===null) return;
        Validator::make(['rule'=>$rule],['rule'=>'array','rule.mode'=>'required|in:workdays,calendar_month',
            'rule.days'=>'nullable|integer|min:1|max:3660','rule.baseDate'=>'required|date_format:Y-m-d'])->validate();
        abort_unless(($rule['baseDate']??'')===self::today(),409,'تغير يوم العمل منذ معاينة الموعد. أعد اختيار المدة لتحديث تاريخ التسليم.');
        $computed=self::preview($rule['mode'],(int)($rule['days']??0));
        $new['dueDate']=$computed['dueDate'];
        $new['dueRule']=['mode'=>$computed['mode'],'days'=>$computed['days'],'baseDate'=>$computed['baseDate'],
            'dueDate'=>$computed['dueDate'],'excludedWeekdays'=>[5],'setAt'=>now()->toIso8601String(),'setById'=>(string)$actor->id];
    }
}

<?php
namespace App\Services\R29;
use App\Models\User;
use Illuminate\Validation\ValidationException;
final class Guards
{
    public static function employee(User $actor,?array $old,array &$d): void {
        try{Rules::profile($d,$old);}catch(\DomainException $e){throw ValidationException::withMessages(['employee'=>$e->getMessage()]);}
        if(HrWorkflow::$internal)return;
        if($old){
            $prior=fn($field)=>array_values(array_filter($old[$field]??[],fn($r)=>($r['source']??'')==='r29'||($r['source']??'')==='self_r29'));
            $after=fn($field)=>array_values(array_filter($d[$field]??[],fn($r)=>($r['source']??'')==='r29'||($r['source']??'')==='self_r29'));
            foreach(['evaluationRecords','attendanceRecords']as$field)abort_unless($prior($field)===$after($field),422,'السجلات الموثقة تعدل من أدوات R29 المخصصة مع السبب والصلاحية.');
        }else foreach(['evaluationRecords','attendanceRecords']as$field)foreach($d[$field]??[]as$r)abort_unless(!in_array($r['source']??'',['r29','self_r29'],true),422,'المصدر مسجل من الخادم فقط.');
        abort_unless(($d['leaveUsedBeforeSystemByYear']??[])===($old['leaveUsedBeforeSystemByYear']??[]),422,'عدّل الاستخدام السابق من نموذج الإجازات السنوية لتوثيق السبب.');
    }
}

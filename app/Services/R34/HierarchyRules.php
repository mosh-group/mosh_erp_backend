<?php
namespace App\Services\R34;
/** Deterministic hierarchy validation, also exercised outside HTTP/ORM. */
final class HierarchyRules
{
    public static function validate(array $reportsTo,string $general,array $people): array
    {
        if($general===''||!in_array($general,$people,true))throw new \DomainException('حدد المدير العام من الموظفين النشطين.');
        $out=[];
        foreach($reportsTo as $child=>$parent){
            $child=(string)$child;$parent=(string)$parent;
            if(!in_array($child,$people,true)||!in_array($parent,$people,true))throw new \DomainException('أحد أطراف التسلسل موظف غير نشط أو غير موجود.');
            if($child===$parent)throw new \DomainException('لا يمكن أن يتبع المدير لنفسه.');
            if($child===$general)throw new \DomainException('المدير العام هو نهاية التسلسل ولا يعيّن له مدير أعلى.');
            $out[$child]=$parent;
        }
        foreach(array_keys($out) as $id){$seen=[];$p=(string)$id;
            while($p!==$general){
                if(isset($seen[$p]))throw new \DomainException('توجد حلقة في تبعية المدراء.');$seen[$p]=true;
                if(count($seen)>100)throw new \DomainException('التسلسل الإداري طويل بصورة غير صالحة.');
                if(empty($out[$p]))throw new \DomainException('كل سلسلة مدير يجب أن تنتهي عند المدير العام.');
                $p=(string)$out[$p];
            }
        }
        ksort($out);return $out;
    }
    public static function chain(string $subject,array $parents,string $general,bool $all=true): array
    {
        $seen=[$subject=>true];$out=[];$id=$subject;
        while(!empty($parents[$id])){
            $id=(string)$parents[$id];if(isset($seen[$id]))throw new \DomainException('حلقة في مسار الاعتماد.');
            $seen[$id]=true;$out[]=$id;if(!$all||$id===$general)break;
            if(count($out)>100)throw new \DomainException('مسار اعتماد غير صالح.');
        }
        return $out;
    }
}

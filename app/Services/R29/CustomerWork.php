<?php
namespace App\Services\R29;
use App\Models\{User,Customer,Task,Employee};
use App\Support\{RecordAccess,Records,R19Store};
use Illuminate\Support\Facades\Validator;
final class CustomerWork
{
    public static function canTrack(User $a): bool { return $a->active&&($a->root()||($a->allows('marketing','view')&&$a->allows('marketing','details'))||($a->allows('admin','view')&&$a->allows('admin','reports'))); }
    public static function canReadCustomer(User $a,array $d): bool { return $a->active&&empty($d['deletedAt'])&&($a->root()||($a->allows('admin','view')&&$a->allows('admin','reports'))||RecordAccess::readable($a,'customers',$d,'details')); }
    public static function customer(User $a,string $id): Customer { $c=Customer::findOrFail($id);abort_unless(self::canTrack($a)&&empty($c->data['deletedAt'])&&self::canReadCustomer($a,$c->document()),404);return $c; }
    public static function taskCustomer(Task $t): ?Customer {
        if(!empty($t->customer_id))return Customer::find((string)$t->customer_id);
        if(!empty($t->data['customerId']))return Customer::find((string)$t->data['customerId']);
        $list=Customer::where('data->name',$t->data['customer']??'')->get();return $list->count()===1?$list->first():null;
    }
    public static function canReadTaskFile(User $a,Task $t): bool { $c=self::taskCustomer($t);return self::isOpen($t->data)&&$c&&self::canTrack($a)&&self::canReadCustomer($a,$c->document()); }
    public static function isOpen(array $t): bool { return !in_array($t['status']??'', ['cancelled','archived_invoiced','archived_no_invoice','invoiced','no_invoice','archived'],true)&&empty($t['deletedAt']); }
    public static function tasks(User $a,string $id): array {
        $c=self::customer($a,$id);$out=[];
        foreach(Task::orderBy('created_at')->get()as$t){if(!self::isOpen($t->data))continue;$cid=(string)($t->customer_id?:($t->data['customerId']??''));
            if($cid!==$id&&($cid!==''||(string)(self::taskCustomer($t)?->id??'')!==$id))continue;$out[]=$t->document();}
        usort($out,fn($a,$b)=>(int)\App\Support\R19TaskPriority::urgent($b)<=>(int)\App\Support\R19TaskPriority::urgent($a));
        return ['customer'=>['id'=>$id,'name'=>$c->data['name']],'tasks'=>$out,'readOnly'=>true];
    }
    public static function list(User $a,array $in): array {
        abort_unless(self::canTrack($a)||$a->customerPermission('edit')||($a->active&&$a->allows('sales','view')&&$a->allows('sales','details')),403);$q=trim((string)($in['q']??''));$archived=($in['status']??'active')==='archived';$page=max(1,(int)($in['page']??1));$size=25;$list=[];
        foreach(Customer::orderBy('created_at')->orderBy('id')->get()as$c){$d=$c->document();if(!empty($d['deletedAt'])||!self::canReadCustomer($a,$d)||(($d['active']??true)===false)!==$archived)continue;
            if($q!==''&&!str_contains(mb_strtolower(($d['name']??'').' '.($d['phone']??'')),mb_strtolower($q)))continue;
            $list[]=array_intersect_key($d,array_flip(['id','_version','name','active','phone','contact','archiveHistory']));}
        $total=count($list);$pages=max(1,(int)ceil($total/$size));$page=min($page,$pages);$rows=array_slice($list,($page-1)*$size,$size);foreach($rows as$i=>&$r)$r['ordinal']=($page-1)*$size+$i+1;unset($r);
        return ['items'=>$rows,'total'=>$total,'page'=>$page,'pages'=>$pages,'canArchive'=>$a->customerPermission('edit'),'readOnlyTasks'=>true,'canTrackTasks'=>self::canTrack($a)];
    }
    public static function archive(User $a,string $id,array $in): array {
        Validator::make($in,['_version'=>'required|integer|min:1','archive'=>'required|boolean','reason'=>'required|string|min:3|max:2000'])->validate();
        R19Store::commit($a,'r29:customer:'.$id,$in,function($tx)use($a,$id,$in){
            abort_unless($a->customerPermission('edit'),403);$c=Customer::findOrFail($id);abort_unless($c->version===(int)$in['_version'],409);
            $d=$c->document();$active=!$in['archive'];abort_unless(($d['active']??true)!==$active,409,'حالة العميل مطابقة بالفعل.');
            $history=$d['archiveHistory']??[];$history[]=['action'=>$active?'reactivated':'archived','reason'=>$in['reason'],'by'=>$a->name,'byId'=>(string)$a->id,'at'=>now()->toIso8601String()];
            \App\Services\R34\CommandContext::run('customer-archive',$id,fn()=>Records::change($a,['resource'=>'customers','action'=>'update','id'=>$id,'version'=>$c->version,'data'=>['active'=>$active,'archiveHistory'=>$history]],$tx));return ['id'=>$id];
        });$c=Customer::findOrFail($id);abort_unless(RecordAccess::readable($a,'customers',$c->document()),403);return $c->document();
    }
    public static function adminTasks(User $a): array {
        abort_unless($a->active&&$a->allows('admin','view')&&$a->allows('admin','running'),403);$ids=Employee::where('department','الإدارة')->pluck('id')->map('strval')->all();$out=[];
        foreach(Task::where('department','الإدارة')->orderBy('created_at')->get() as $t){
            $d=$t->document();if(!self::isOpen($t->data))continue;
            $assignedId=(string)($t->employee_id?:($t->data['assigneeId']??''));
            $unassigned=$assignedId==='' && trim((string)($d['assignee']??''))==='';
            // Only authenticated administration staff with admin running scope reach this endpoint.
            if($unassigned || (in_array($assignedId,$ids,true) && RecordAccess::readable($a,'tasks',$d,'details')))$out[]=$d;
        }
        usort($out,fn($a,$b)=>(int)\App\Support\R19TaskPriority::urgent($b)<=>(int)\App\Support\R19TaskPriority::urgent($a));return ['tasks'=>$out];
    }
}

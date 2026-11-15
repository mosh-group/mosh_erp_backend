<?php
namespace App\Http\Controllers;
use App\Models\{User,Setting};
use App\Support\{Records,BusinessRules,RecordAccess};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Validator};
use Illuminate\Support\Str;

class TransactionController extends Controller
{
    public function store(Request $r) {
        foreach (is_array($r->input('operations'))?$r->input('operations'):[] as $op) if (is_array($op)&&isset($op['action'])) abort_unless(in_array($op['action'],['create','update','delete'],true),400,'عملية غير مدعومة.');
        $v=$r->validate(['operations'=>'required|array|min:1|max:500','operations.*.resource'=>'required|string|max:100',
            'operations.*.id'=>'sometimes', 'operations.*.action'=>'required|in:create,update,delete','operations.*.data'=>'sometimes|array','operations.*.version'=>'nullable|integer|min:1']);
        foreach ($v['operations'] as $i=>$op) if (in_array($op['action'],['create','update'],true)) Validator::make($op,['data'=>'present|array'])->validate();
        return $this->ok(self::commit($r,$v['operations'],true));
    }
    public static function commit(Request $r,array $ops,bool $requireKey=true,?string $undoOf=null): array {
        $key=$r->header('Idempotency-Key');
        if ($requireKey) abort_unless(is_string($key)&&strlen($key)>=8&&strlen($key)<=190,422,'أرسل Idempotency-Key صالحاً.');
        $key=$key?:Str::uuid()->toString(); $hash=hash('sha256',json_encode([$ops,$undoOf]));
        return DB::transaction(function ()use($r,$ops,$key,$hash,$undoOf) {
            DB::table('app_locks')->where('id','business')->increment('revision');
            $old=DB::table('transactions')->where('actor_id',$r->user()->id)->where('idempotency_key',$key)->first();
            if ($old) { abort_unless($old->request_hash===$hash,409,'مفتاح الطلب مستخدم لعملية مختلفة.'); return json_decode($old->response,true); }
            if ($undoOf) abort_if(DB::table('transactions')->where('undo_of',$undoOf)->exists(),409,'تم التراجع عن هذه العملية.');
            $tx=(string)Str::ulid(); $changes=[];
            foreach ($ops as $op) $changes[]=Records::change($r->user(),$op,$tx);
            BusinessRules::afterTransaction($r->user(),$changes,$tx);
            // Reload after derived totals, journals and workflow effects are committed.
            $public=[];
            foreach ($changes as $c) {
                $data=!empty($c['deleted'])?null:$c['after'];
                if ($data && !str_starts_with($c['resource'],'settings/')) {
                    if (!RecordAccess::readable($r->user(),$c['resource'],$data)) continue;
                    $data=RecordAccess::document($r->user(),$c['resource'],$data);
                }
                $public[]=['resource'=>$c['resource'],'id'=>$c['id'],'data'=>$data];
            }
            $response=['transactionId'=>$tx,'revision'=>DB::table('app_locks')->where('id','business')->value('revision'),'changes'=>$public];
            DB::table('transactions')->insert(['id'=>$tx,'actor_id'=>$r->user()->id,'idempotency_key'=>$key,'request_hash'=>$hash,
                'changes'=>json_encode($changes,JSON_UNESCAPED_UNICODE),'response'=>json_encode($response,JSON_UNESCAPED_UNICODE),'undo_of'=>$undoOf,'created_at'=>now(),'updated_at'=>now()]);
            return $response;
        },3);
    }
    public function undo(Request $r,string $id) {
        $tx=DB::table('transactions')->where('id',$id)->first(); abort_unless($tx,404);
        abort_unless($tx->actor_id===$r->user()->id,403);
        $safe=['customers','services','salespeople','sales-teams','quotation-templates','wisdom','print-layouts','admin-decisions','admin-meetings'];
        $ops=[];
        foreach (array_reverse(json_decode($tx->changes,true)) as $c) {
            abort_unless(in_array($c['resource'],$safe,true),409,'هذه العملية معتمدة أو مرتبطة بسير عمل؛ استخدم الإلغاء أو العكس المخصص.');
            if ($c['after']===null) $ops[]=['resource'=>$c['resource'],'id'=>$c['id'],'action'=>'create','data'=>$c['before']];
            elseif ($c['before']===null) $ops[]=['resource'=>$c['resource'],'id'=>$c['id'],'action'=>'delete','version'=>$c['after']['_version']];
            else $ops[]=['resource'=>$c['resource'],'id'=>$c['id'],'action'=>'update','version'=>$c['after']['_version'],'data'=>$c['before']];
        }
        return $this->ok(self::commit($r,$ops,true,$id));
    }
}

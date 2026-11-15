<?php
namespace App\Http\Controllers;
use App\Support\{Records,RecordAccess};
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class ResourceController extends Controller
{
    public static function rows(Request $r,string $resource): array {
        $class=Records::model($resource);
        $rows=$class::orderBy('created_at')->get()->filter(fn($x)=>empty($x->data['deletedAt'])&&($resource!=='employees'||empty($x->data['archivedAt'])))->filter(fn($x)=>RecordAccess::readable($r->user(),$resource,$x->document()))
            ->map(fn($x)=>RecordAccess::document($r->user(),$resource,$x->document()))->values()->all();
        if ($resource==='wisdom') {
            $order=array_flip(Setting::valueOf('wisdom')['order']??[]);
            usort($rows,fn($a,$b)=>($order[(string)$a['id']]??PHP_INT_MAX)<=>($order[(string)$b['id']]??PHP_INT_MAX));
        }
        if($resource==='tasks')usort($rows,fn($a,$b)=>(int)\App\Support\R19TaskPriority::urgent($b)<=>(int)\App\Support\R19TaskPriority::urgent($a));
        return $rows;
    }
    public function index(Request $r) {
        $res=$r->route('resource'); $rows=self::rows($r,$res);
        $v=$r->validate(['q'=>'nullable|string|max:200','page'=>'sometimes|integer|min:1','perPage'=>'sometimes|integer|between:1,100',
            'order'=>'sometimes|in:asc,desc','sort'=>'nullable|string|max:80','filter'=>'nullable|array','from'=>'nullable|date_format:Y-m-d','to'=>'nullable|date_format:Y-m-d','scope'=>'nullable|in:mine,department,all']);
        foreach ($v['filter']??[] as $field=>$value) abort_unless(in_array($field,Records::scalarFields($res),true)&&is_scalar($value),422,'حقل تصفية غير صالح.');
        $rows=array_values(array_filter($rows,function($d)use($v,$r) {
            if (($v['scope']??'')==='mine'&&!RecordAccess::own($r->user(),$d)) return false;
            if (($v['scope']??'')==='department'&&($d['department']??'')!==$r->user()->department) return false;
            if (!empty($v['q']) && !str_contains(mb_strtolower(json_encode($d,JSON_UNESCAPED_UNICODE)),mb_strtolower($v['q']))) return false;
            foreach ($v['filter']??[] as $field=>$value) { abort_unless(is_scalar($value),422); if ((string)data_get($d,$field,'')!==(string)$value) return false; }
            $date=$d['date']??$d['createdDate']??substr($d['createdAt']??'',0,10);
            return (empty($v['from'])||$date>=$v['from'])&&(empty($v['to'])||$date<=$v['to']);
        }));
        if ($sort=$v['sort']??'') {
            abort_unless(in_array($sort,Records::scalarFields($res),true),422,'حقل ترتيب غير صالح.');
            usort($rows,fn($a,$b)=>(($v['order']??'asc')==='desc'?-1:1)*(data_get($a,$sort,'')<=>data_get($b,$sort,'')));
        }
        if($res==='tasks')usort($rows,fn($a,$b)=>(int)\App\Support\R19TaskPriority::urgent($b)<=>(int)\App\Support\R19TaskPriority::urgent($a));
        $total=count($rows); $page=$v['page']??1; $size=$v['perPage']??25;
        return $this->ok(array_slice($rows,($page-1)*$size,$size),200,['page'=>$page,'perPage'=>$size,'total'=>$total,'lastPage'=>max(1,(int)ceil($total/$size))]);
    }
    public function show(Request $r,string $id) {
        $res=$r->route('resource'); $d=Records::find($res,$id)->document();
        abort_unless(empty($d['deletedAt'])&&RecordAccess::readable($r->user(),$res,$d,'details'),404);
        return $this->ok(RecordAccess::document($r->user(),$res,$d));
    }
    public function store(Request $r) { return $this->write($r,'create'); }
    public function update(Request $r,string $id) { return $this->write($r,'update',$id); }
    public function destroy(Request $r,string $id) { return $this->write($r,'delete',$id); }
    private function write(Request $r,string $action,?string $id=null) {
        $data=$r->except('_version');
        $op=['resource'=>$r->route('resource'),'action'=>$action,'version'=>$r->input('_version'),'data'=>$data];
        if ($id!==null) $op['id']=$id;
        $result=TransactionController::commit($r,[$op],false);
        return $this->ok($action==='delete'?null:($result['changes'][0]['data']??null),$action==='create'?201:200);
    }
    public function setting(Request $r) {
        $key=$r->route('setting'); $spec=config('mosh.spec.settings.'.$key); abort_unless($spec,404);
        if ($r->isMethod('PUT')) {
            $v=$r->validate(['value'=>'present|array','_version'=>'required|integer|min:1']);
            $result=TransactionController::commit($r,[['resource'=>'settings/'.$key,'action'=>'update','id'=>$key,'version'=>$v['_version'],'data'=>$v['value']]],false);
            return $this->ok($result['changes'][0]['data']);
        }
        abort_unless(RecordAccess::settingsReadable($r->user(),$key),403);
        $s=Setting::findOrFail($key); return $this->ok(['value'=>$key==='department-managers'?(object)\App\Support\DepartmentManagers::normalize($s->value):$s->value,'_version'=>$s->version]);
    }
}

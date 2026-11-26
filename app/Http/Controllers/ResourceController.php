<?php
namespace App\Http\Controllers;
use App\Support\{Records,RecordAccess,TaskReadQuery};
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class ResourceController extends Controller
{
    public static function rows(Request $r,string $resource): array {
        if ($resource==='tasks') {
            $tasks = TaskReadQuery::forDatabase();
            return Setting::readOnlySnapshot(fn() => $tasks->documents($r->user(),
                $tasks->ordered($tasks->visibleTo($r->user()))->get()));
        }
        $class=Records::model($resource);
        $rows=$class::orderBy('created_at')->get()->filter(fn($x)=>empty($x->data['deletedAt'])&&($resource!=='employees'||empty($x->data['archivedAt'])))->filter(fn($x)=>RecordAccess::readable($r->user(),$resource,$x->document()))
            ->map(fn($x)=>RecordAccess::document($r->user(),$resource,$x->document()))->values()->all();
        if ($resource==='wisdom') {
            $order=array_flip(Setting::valueOf('wisdom')['order']??[]);
            usort($rows,fn($a,$b)=>($order[(string)$a['id']]??PHP_INT_MAX)<=>($order[(string)$b['id']]??PHP_INT_MAX));
        }
        return $rows;
    }
    public function index(Request $r) {
        $res = $r->route('resource');
        $v = $this->listFilters($r, $res);
        if ($res === 'tasks') {
            return Setting::readOnlySnapshot(function () use ($r, $v) {
                $tasks = TaskReadQuery::forDatabase();
                $query = $tasks->ordered($tasks->visibleTo($r->user(), $v), $v);
                $page = $query->paginate($v['perPage'] ?? 25, ['*'], 'page', $v['page'] ?? 1);
                return $this->ok($tasks->documents($r->user(), $page->items()), 200, [
                    'page'=>$page->currentPage(), 'perPage'=>$page->perPage(),
                    'total'=>$page->total(), 'lastPage'=>$page->lastPage(),
                ]);
            });
        }
        $rows = self::rows($r, $res);
        $rows=array_values(array_filter($rows,function($d)use($v,$r) {
            if (($v['scope']??'')==='mine'&&!RecordAccess::own($r->user(),$d)) return false;
            if (($v['scope']??'')==='department'&&($d['department']??'')!==$r->user()->department) return false;
            if (!empty($v['q']) && !str_contains(mb_strtolower(json_encode($d,JSON_UNESCAPED_UNICODE)),mb_strtolower($v['q']))) return false;
            foreach ($v['filter']??[] as $field=>$value) { abort_unless(is_scalar($value),422); if ((string)data_get($d,$field,'')!==(string)$value) return false; }
            $date=$d['date']??$d['createdDate']??substr($d['createdAt']??'',0,10);
            return (empty($v['from'])||$date>=$v['from'])&&(empty($v['to'])||$date<=$v['to']);
        }));
        if ($sort=$v['sort']??'') {
            usort($rows,fn($a,$b)=>(($v['order']??'asc')==='desc'?-1:1)*(data_get($a,$sort,'')<=>data_get($b,$sort,'')));
        }
        if($res==='tasks')usort($rows,fn($a,$b)=>(int)\App\Support\R19TaskPriority::urgent($b)<=>(int)\App\Support\R19TaskPriority::urgent($a));
        $total=count($rows); $page=$v['page']??1; $size=$v['perPage']??25;
        return $this->ok(array_slice($rows,($page-1)*$size,$size),200,['page'=>$page,'perPage'=>$size,'total'=>$total,'lastPage'=>max(1,(int)ceil($total/$size))]);
    }
    private function listFilters(Request $r, string $resource): array {
        $rules = ['q'=>'nullable|string|max:200','page'=>'sometimes|integer|min:1','perPage'=>'sometimes|integer|between:1,100',
            'order'=>'sometimes|in:asc,desc','sort'=>'nullable|string|max:80','filter'=>'nullable|array',
            'from'=>'nullable|date_format:Y-m-d','to'=>'nullable|date_format:Y-m-d','scope'=>'nullable|in:mine,department,all'];
        if ($resource === 'tasks') $rules['view'] = 'nullable|in:main';
        $filters = $r->validate($rules);
        $scalar = Records::scalarFields($resource);
        foreach ($filters['filter'] ?? [] as $field => $value) {
            abort_unless(in_array($field, $scalar, true) && is_scalar($value), 422, 'حقل تصفية غير صالح.');
        }
        if (!empty($filters['sort'])) abort_unless(in_array($filters['sort'], $scalar, true), 422, 'حقل ترتيب غير صالح.');
        return $filters;
    }
    public function taskSummary(Request $r) {
        $filters = $this->listFilters($r, 'tasks');
        return Setting::readOnlySnapshot(function () use ($r, $filters) {
            $tasks = TaskReadQuery::forDatabase();
            return $this->ok($tasks->summary($tasks->visibleTo($r->user(), $filters)))->header('Cache-Control', 'private, no-store');
        });
    }
    public function ownTasks(Request $r) {
        return Setting::readOnlySnapshot(fn() => $this->ok(TaskReadQuery::forDatabase()->mine($r->user()))
            ->header('Cache-Control', 'private, no-store'));
    }
    public function taskFamily(Request $r, string $id) {
        return Setting::readOnlySnapshot(fn() => $this->ok(TaskReadQuery::forDatabase()->family($r->user(), $id))
            ->header('Cache-Control', 'private, no-store'));
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
        $change=$result['changes'][0]??[];
        $data=$change['data']??null;
        if (($change['outcome']??'')==='follow_up') $data=['id'=>$change['id'],'outcome'=>'follow_up'];
        return $this->ok($action==='delete'?null:$data,$action==='create'?201:200);
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

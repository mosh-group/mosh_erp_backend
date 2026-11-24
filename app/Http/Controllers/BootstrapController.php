<?php
namespace App\Http\Controllers;
use App\Models\{Setting,Role,User};
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
class BootstrapController extends Controller
{
    private const SHARED = [
        'customers', 'services', 'tasks', 'employees', 'hr-requests', 'announcements',
        'work-presence', 'field-work', 'field-templates', 'wisdom',
    ];

    private const SECTION_MODULES = [
        'dashboard' => [], 'employeePortal' => [],
        'tasks' => ['purchases'], 'sales' => ['sales'],
        'marketing' => [], 'social' => [], 'hr' => ['hr'],
        'admin' => ['admin', 'purchases'],
        'it' => [], 'design' => [], 'photo' => [], 'editing' => [], 'promotion' => [], 'platform' => [],
        'purchases' => ['purchases', 'warehouses'],
        'logistics' => ['logistics', 'purchases', 'warehouses'],
        'warehouses' => ['warehouses', 'logistics', 'purchases'],
        'accounting' => ['accounting', 'purchases'],
        'archive' => [], 'instructions' => [],
    ];

    private const SECTION_DEPENDENCIES = [
        'admin' => ['financial-documents', 'payroll-hours', 'payroll-runs', 'hr-approvals'],
        'accounting' => ['quotations', 'payroll-hours', 'payroll-runs'],
    ];

    public function __invoke(Request $r) {
        return Setting::readOnlySnapshot(fn() => $this->build($r));
    }
    private function build(Request $r) {
        $u=$r->user(); $state=['salesOrders'=>[],'operations18'=>['version'=>1,'audit'=>[]],
            'finance21'=>['version'=>1,'audit'=>[]],'finance22'=>['version'=>1,'chart'=>[],'baseline'=>[],'enabledAt'=>now()->toIso8601String(),'audit'=>[]],
            'finance23'=>['version'=>1],'fieldWorkSettingsV30B'=>['version'=>'30B','initialized'=>true],
            'fieldWorkV30B'=>['version'=>'30B'],'employeeContracts'=>[]];
        $section = $r->validate(['section' => ['sometimes', 'required', Rule::in(array_keys(self::SECTION_MODULES))]])['section'] ?? null;
        $loaded = [];
        foreach (config('mosh.spec.resources') as $resource=>$spec) {
            $include = $section === null || in_array($resource, self::SHARED, true)
                || in_array($spec['module'], self::SECTION_MODULES[$section], true)
                || in_array($resource, self::SECTION_DEPENDENCIES[$section] ?? [], true);
            if ($include) $loaded[] = $resource;
            $rows = $include && !$u->must_change ? ResourceController::rows($r, $resource) : [];
            if ($spec['mode']==='map') $rows=(object)array_column($rows,null,'id');
            data_set($state,$spec['statePath'],$rows);
        }
        $settings=[];
        foreach (config('mosh.spec.settings') as $key=>$spec) {
            $s=Setting::find($key);
            $value=(!$u->must_change&&\App\Support\RecordAccess::settingsReadable($u,$key))?($s?->value??[]):[];
            if ($key === 'department-managers') $value = (object)\App\Support\DepartmentManagers::normalize($value);
            data_set($state,$spec['path'],$value); $settings[$key]=['value'=>$value,'_version'=>$s?->version??1];
        }
        $roles=($u->globalPermission('managePermissions')||$u->globalPermission('manageUsers'))?Role::all():collect([$u->role])->filter();
        $users=$u->globalPermission('manageUsers')?User::all():collect([$u]);
        return $this->ok(['state'=>$state,'settings'=>$settings,'loadedResources'=>$loaded,'user'=>$u->document(),'roles'=>$roles->map(fn($x)=>$x->document())->values(),
            'users'=>$users->map(fn($x)=>$x->document())->values(),'security'=>$u->root()?Setting::valueOf('security'):Arr::only(Setting::valueOf('security'),['minLength']),
            'revision'=>DB::table('app_locks')->where('id','business')->value('revision'),'csrfToken'=>csrf_token(),
            'resources'=>array_map(fn($s)=>Arr::only($s,['statePath','mode']),config('mosh.spec.resources')),
            'settingPaths'=>array_map(fn($s)=>$s['path'],config('mosh.spec.settings'))]);
    }
}

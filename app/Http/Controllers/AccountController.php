<?php
namespace App\Http\Controllers;
use App\Models\{User,Role,Employee,Setting};
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    private function guard(Request $r, string $resource): void {
        abort_unless($r->user()->globalPermission($resource==='roles'?'managePermissions':'manageUsers'),403);
    }
    private function resource(Request $r): string { return $r->route('accounts'); }
    public function index(Request $r) {
        $kind=$this->resource($r); $this->guard($r,$kind);
        $class=$kind==='roles'?Role::class:User::class;
        $q=$class::query();
        if ($s=trim((string)$r->input('q',''))) $q->where('name','like','%'.$s.'%');
        $p=$q->orderBy('name')->paginate(min(100,max(1,(int)$r->input('perPage',50))));
        return $this->ok(array_map(fn($x)=>$x->document(),$p->items()),200,['page'=>$p->currentPage(),'perPage'=>$p->perPage(),'total'=>$p->total(),'lastPage'=>$p->lastPage()]);
    }
    public function show(Request $r, string $id) {
        $kind=$this->resource($r); $this->guard($r,$kind);
        $class=$kind==='roles'?Role::class:User::class;
        return $this->ok($class::findOrFail($id)->document());
    }
    public function store(Request $r) { return $this->write($r,null); }
    public function update(Request $r, string $id) { return $this->write($r,$id); }
    private function write(Request $r, ?string $id) {
        $kind=$this->resource($r); $this->guard($r,$kind);
        $out=DB::transaction(function () use($r,$id,$kind) {
            DB::table('app_locks')->where('id','business')->increment('revision');
            $class=$kind==='roles'?Role::class:User::class;
            $row=$id?$class::findOrFail($id):null;
            if ($row) abort_unless((int)$r->input('_version')===$row->version,409,'تغير السجل. حدث الصفحة ثم حاول مجدداً.');
            $before=$row?->document();
            // Validate the merged record: PATCH changes only the fields supplied by the caller.
            if ($row) $r->merge(array_replace($before,$r->all()));
            if ($kind==='roles') {
                abort_if($row?->id==='app_programmer',403,'لا يمكن تعديل صلاحيات الحساب الأساسي.');
                $v=$r->validate(['name'=>'required|string|max:150','scope'=>'required|in:all,department,department_limited,custom',
                    'taskScope'=>'required|in:all,department,mine','landing'=>'nullable|string|max:80',
                    'scopeActions'=>'present|array','scopeActions.*'=>'boolean','globals'=>'present|array','globals.*'=>'boolean',
                    'modules'=>'present|array','modules.*'=>'array','modules.*.*'=>'boolean']);
                $this->permissionCeiling($r->user(),$v);
                $row??=new Role(['id'=>'ROLE-'.Str::ulid(),'protected'=>false,'version'=>0]);
                $row->fill(['name'=>$v['name'],'data'=>$v,'version'=>$row->version+1])->save();
            } else {
                abort_if($row?->root() && !$r->user()->root(),403);
                $p=Setting::valueOf('security');
                $r->merge(['username'=>mb_strtolower(trim((string)$r->input('username')))]);
                $v=$r->validate(['name'=>'required|string|max:150','username'=>['required','string','min:3','max:80','regex:/^[\pL\pN_.@-]+$/u',Rule::unique('users')->ignore($id)],
                    'roleId'=>'required|string|exists:roles,id','employeeId'=>['nullable','string','exists:employees,id',Rule::unique('users','employee_id')->ignore($id)],
                    'department'=>'nullable|string|max:150','active'=>'required|boolean','mustChange'=>'boolean',
                    'taskScope'=>'nullable|in:inherit,mine,department,all','landing'=>'nullable|string|max:80',
                    'password'=>[$id?'nullable':'required','string','min:'.max(6,$p['minLength']??6),'max:128']]);
                abort_if($v['roleId']==='app_programmer' && !$row?->root(),403);
                if ($row?->root()) abort_unless($v['active'] && $v['roleId']==='app_programmer',422,'لا يمكن تعطيل أو تغيير دور الحساب الأساسي.');
                abort_if($row?->id===$r->user()->id && !$v['active'],422,'لا يمكن تعطيل حسابك الحالي.');
                $role=Role::findOrFail($v['roleId']);
                if (($v['taskScope']??'')==='mine'||$v['roleId']==='role_employee'||($role->data['scope']??'')==='department_limited') abort_unless(!empty($v['employeeId']),422,'اربط الحساب بملف الموظف.');
                $this->permissionCeiling($r->user(),$role->data);
                if (!$r->user()->root() && ($v['taskScope']??'inherit')==='all') abort_unless($r->user()->taskScope()==='all',403);
                $row??=new User(['id'=>'USR-'.Str::ulid(),'data'=>[],'auth_version'=>1,'version'=>0,'is_root'=>false]);
                $employee=isset($v['employeeId'])?Employee::find($v['employeeId']):null;
                abort_if($employee&&!empty($employee->data['archivedAt']),422,'ملف الموظف مؤرشف؛ استعده أولاً قبل ربط حساب دخول.');
                if (!$r->user()->root()&&($r->user()->role?->data['scope']??'')!=='all') abort_unless(($employee?->department??($v['department']??''))===$r->user()->department,403,'إنشاء وإدارة الحسابات خارج قسمك غير متاح.');
                $attrs=['name'=>$v['name'],'username'=>mb_strtolower(trim($v['username'])),'role_id'=>$v['roleId'],
                    'employee_id'=>$employee?->id,'department'=>$employee?->data['department']??($v['department']??''),
                    'active'=>$v['active'],'must_change'=>$v['mustChange']??($id?$row->must_change:($p['forceNewPassword']??true)),
                    'data'=>array_merge($row->data??[],['taskScope'=>$v['taskScope']??'inherit','landing'=>$v['landing']??'dashboard']),
                    'version'=>$row->version+1];
                if (!empty($v['password'])) { $attrs['password']=$v['password']; $attrs['auth_version']=$row->auth_version+1; }
                $row->fill($attrs)->save();
            }
            Audit::record($r->user(),'security',$kind,$row->id,$id?'update':'create',$before,$row->document());
            return $row->document();
        });
        return $this->ok($out,$id?200:201);
    }
    private function permissionCeiling(User $actor, array $data): void {
        if ($actor->root()) return;
        if (($data['scope']??'')==='all') abort_unless(($actor->role?->data['scope']??'')==='all',403);
        foreach ($data['scopeActions']??[] as $action=>$yes) if ($yes) abort_unless($actor->allows(User::departmentApp($actor->department),$action),403);
        foreach ($data['globals']??[] as $key=>$yes) if ($yes) abort_unless($actor->globalPermission($key),403);
        foreach ($data['modules']??[] as $module=>$actions) foreach ($actions as $action=>$yes) if ($yes) abort_unless($actor->allows($module,$action),403);
    }
    public function destroy(Request $r,string $id) {
        $kind=$this->resource($r); $this->guard($r,$kind);
        DB::transaction(function () use($r,$id,$kind) {
            DB::table('app_locks')->where('id','business')->increment('revision');
            $class=$kind==='roles'?Role::class:User::class; $row=$class::findOrFail($id);
            abort_unless((int)$r->input('_version')===$row->version,409);
            if ($kind==='roles') abort_if($row->protected || $row->users()->exists(),409,'الدور محمي أو مستخدم.');
            else abort_if($row->root() || $id===$r->user()->id,403);
            Audit::record($r->user(),'security',$kind,$id,'delete',$row->document()); $row->delete();
        });
        return $this->ok(null);
    }
    public function permissions(Request $r,string $id) {
        return DB::transaction(function () use ($r,$id) {
            DB::table('app_locks')->where('id','business')->increment('revision');
        abort_unless($r->user()->globalPermission('managePermissions'),403);
        $u=User::findOrFail($id); abort_if($u->root(),403);
        $v=$r->validate(['globalOverrides'=>'present|array','globalOverrides.*'=>'boolean',
            'moduleOverrides'=>'present|array','moduleOverrides.*'=>'array','moduleOverrides.*.*'=>'boolean','_version'=>'required|integer']);
        abort_unless($v['_version']===$u->version,409);
        $this->permissionCeiling($r->user(),['globals'=>$v['globalOverrides'],'modules'=>$v['moduleOverrides']]);
        $old=$u->document(); unset($v['_version']);
        $u->update(['data'=>array_merge($u->data??[],$v),'version'=>$u->version+1]);
        Audit::record($r->user(),'security','users',$id,'permissions',$old,$u->document());
        return $this->ok($u->document());
        });
    }
    public function password(Request $r,string $id) {
        return DB::transaction(function () use ($r,$id) {
            DB::table('app_locks')->where('id','business')->increment('revision');
        $this->guard($r,'users'); $u=User::findOrFail($id);
        abort_if($u->root() && !$r->user()->root(),403);
        $min=max(6,Setting::valueOf('security')['minLength']??6);
        $v=$r->validate(['password'=>'required|string|min:'.$min.'|max:128|confirmed','_version'=>'required|integer']);
        abort_unless($v['_version']===$u->version,409);
        $u->update(['password'=>$v['password'],'must_change'=>true,'auth_version'=>$u->auth_version+1,'version'=>$u->version+1,'failed_attempts'=>0,'locked_until'=>null]);
        Audit::record($r->user(),'security','users',$id,'password_reset');
        return $this->ok($u->document());
        });
    }
}

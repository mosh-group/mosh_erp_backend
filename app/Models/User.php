<?php
namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable;
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $hidden = ['password','remember_token','failed_attempts','locked_until','auth_version'];
    protected function casts(): array
    {
        return ['data'=>'array','active'=>'boolean','is_root'=>'boolean','must_change'=>'boolean',
            'locked_until'=>'datetime','last_login'=>'datetime','password'=>'hashed','version'=>'integer'];
    }
    public function role() { return $this->belongsTo(Role::class); }
    public function employee() { return $this->belongsTo(Employee::class); }
    public static function departmentApp(string $department): string
    {
        return ['الإدارة'=>'admin','تكنولوجيا المعلومات'=>'it','قسم تكنولوجيا المعلومات'=>'it','IT'=>'it','الموارد البشرية'=>'hr','المبيعات'=>'sales','التسويق'=>'marketing','السوشال ميديا'=>'social','السوشل ميديا'=>'social','قسم السوشال ميديا'=>'social',
            'التصميم'=>'design','التصوير'=>'photo','المونتاج'=>'editing','الترويج'=>'promotion',
            'الخدمات الإدارية'=>'logistics','اللوجستيات'=>'logistics','المشتريات'=>'purchases','المحاسبة'=>'accounting',
            'المستودعات العامة'=>'warehouses','المهمات'=>'tasks'][$department] ?? $department;
    }
    public function root(): bool { return $this->is_root && $this->role_id === 'app_programmer'; }
    public function globalPermission(string $key): bool
    {
        if (!$this->active) return false;
        if ($this->root()) return true;
        if ($key==='appearanceSettings' && $this->globalPermission('systemCustomization')) return true;
        return (bool) data_get($this->data, 'globalOverrides.'.$key, data_get($this->role?->data, 'globals.'.$key, false));
    }
    public function allows(string $module, string $action='view'): bool
    {
        if (!$this->active) return false;
        if ($this->root() || $module === 'dashboard') return true;
        $role=$this->role?->data ?? [];
        $base=(bool)data_get($role,'modules.'.$module.'.'.$action,false);
        if ($module==='social' && $this->legacySocialAccess() && $action==='view') $base=true;

        if (($role['scope']??'')==='all') $base=true;
        if (in_array($role['scope']??'', ['department','department_limited'],true) && ($module===self::departmentApp($this->department) || $this->managesModule($module))) {
            $base=(bool)data_get($role,'scopeActions.'.$action,false);
        }
        return (bool)data_get($this->data,'moduleOverrides.'.$module.'.'.$action,$base);
    }
    /** Legacy publishing grants retain access to the moved social workspace only, not department approvals. */
    public function legacySocialAccess(): bool
    {
        if (!$this->active || !$this->allows('marketing','view')) return false;
        foreach (['connectInstagram','publishInstagramDirect','scheduleInstagramDirect','approveInstagramPosts','viewInstagramInsights','connectFacebook','publishFacebookDirect','scheduleFacebookDirect','approveFacebookPosts','viewFacebookInsights'] as $key) {
            if ($this->globalPermission($key)) return true;
        }
        return false;
    }
    /** Customer creation/editing are separate permissions, independent of access to Sales screens. */
    public function customerPermission(string $action): bool
    {
        if (!$this->active) return false;
        if ($this->root()) return true;
        $key = $action === 'edit' ? 'editCustomers' : 'createCustomers';
        $override = data_get($this->data, 'globalOverrides.'.$key);
        if (is_bool($override)) return $override;
        $roleValue = data_get($this->role?->data, 'globals.'.$key);
        if ($roleValue === true) return true;
        if ($action === 'edit') return false;
        // Preserve the existing Sales/Marketing create rights until an explicit override is set.
        return $this->allows('sales', 'create') || $this->allows('marketing', 'create');
    }

    public function taskScope(): string
    {
        if ($this->root()) return 'all';
        $own=$this->data['taskScope']??'inherit';
        return in_array($own,['mine','department','all'],true)?$own:($this->role?->data['taskScope']??'mine');
    }
    public function manages(string $department): bool
    {
        if (!$this->active) return false;
        if ($this->root()) return true;
        $employee = $this->employee;
        if (!$employee || !\App\Support\R19HrAccess::activeEmployee($employee)) return false;
        return (string)(Setting::valueOf('department-managers')[$department] ?? '') === (string)$this->employee_id;
    }
    private function managesModule(string $module): bool
    {
        if (!$this->employee_id) return false;
        foreach (\App\Support\DepartmentManagers::normalize(Setting::valueOf('department-managers')) as $department => $id) {
            if ($id === (string)$this->employee_id && self::departmentApp($department) === $module && $this->manages($department)) return true;
        }
        return false;
    }
    public function administration(): bool
    {
        return $this->root() || ($this->allows('admin','approve') && ($this->role?->data['scope']??'')==='all') || ($this->department==='الإدارة' && $this->manages('الإدارة'));
    }
    public function effectivePermissions(): array
    {
        $modules=['it','dashboard','tasks','admin','hr','sales','marketing','social','design','photo','editing','promotion','logistics','purchases','accounting','warehouses','platform','archive','instructions'];
        $actions=['view','dashboard','running','approvals','list','details','create','edit','status','assign','delete','approve','attachments','reports','print','export'];
        $globals=['communicationOversight','connectFacebook','publishFacebookDirect','scheduleFacebookDirect','approveFacebookPosts','viewFacebookInsights','viewITSecrets','manageITAccounts','connectInstagram','scheduleInstagramDirect','publishInstagramDirect','approveInstagramPosts','viewInstagramInsights','systemCustomization','appearanceSettings','manageUsers','managePermissions','viewPasswords','auditLog','createTasks','createCustomers','editCustomers','notifications','viewAllTasks','editCoreTaskData','deleteTasks','viewArchive','zktecoStatus','zktecoSyncActivity','zktecoUnmapped','zktecoRetry','zktecoReconciliation','zktecoErrors','zktecoDeviceDetails','zktecoRawAudit','zktecoMappings','zktecoMappingActions','manageOfficialStationery','approveOfficialSeal'];
        $permissions=[];foreach ($modules as $module) foreach ($actions as $action) $permissions[$module][$action]=$this->allows($module,$action);
        return ['role'=>$this->role?->document(),'effectivePermissions'=>['modules'=>$permissions,'globals'=>array_combine($globals,array_map(fn($key)=>$key==='createCustomers'?$this->customerPermission('create'):($key==='editCustomers'?$this->customerPermission('edit'):$this->globalPermission($key)),$globals))],'effectiveTaskScope'=>$this->taskScope()];
    }
    public function document(): array
    {
        return array_merge($this->data??[], ['id'=>$this->id,'name'=>$this->name,'username'=>$this->username,
            'roleId'=>$this->role_id,'employeeId'=>$this->employee_id??'','department'=>$this->department,
            'active'=>$this->active,'isRoot'=>$this->root(),'mustChange'=>$this->must_change,
            'lastLogin'=>$this->last_login?->toIso8601String()??'','createdAt'=>$this->created_at?->toIso8601String(),
            '_version'=>$this->version]);
    }
}

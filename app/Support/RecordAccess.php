<?php
namespace App\Support;
use App\Models\{User,Task,Employee};
use Illuminate\Support\Arr;

class RecordAccess
{
    public static function own(User $u, array $d): bool {
        return $u->employee_id && (string)($d['employeeId']??$d['assigneeId']??'')===(string)$u->employee_id;
    }
    public static function taskOwn(User $u,array $d): bool {
        $assigned=array_key_exists('assigneeId',$d)?$d['assigneeId']:($d['employeeId']??'');
        return (string)($u->employee_id??'')!=='' && (string)$assigned===(string)$u->employee_id;
    }
    public static function module(string $resource, array $d=[]): string {
        if ($resource==='tasks') return User::departmentApp($d['department']??'tasks');
        if ($resource==='support-requests') return ($d['type']??'')==='logistics'?'logistics':'purchases';
        return config('mosh.spec.resources.'.$resource.'.module','');
    }
    public static function readable(User $u,string $resource,array $d,string $action='list'): bool {
        if (!$u->active) return false;
        if ($u->root()) return true;
        // All active accounts need the staff directory for assignments/managers.
        // document() still removes private HR fields from other employees.
        if ($resource==='employees') return true;
        // Customers are shared: denying Sales must not cancel a Marketing grant.
        if ($resource==='customers') {
            foreach (['sales','marketing','accounting'] as $customerModule) {
                if ($u->allows($customerModule,'view') && $u->allows($customerModule,$action)) return true;
            }
            if (in_array($action,['list','details'],true) && $u->allows('social','view')) return true;
        }
        $m=self::module($resource,$d);
        foreach (['view',$action] as $permission) if (data_get($u->data,'moduleOverrides.'.$m.'.'.$permission)===false) return false;
        if ($resource==='employment-contracts') return \App\Services\R29\HrWorkflow::hr($u,$action==='list'?'details':$action);
        if ($resource==='print-layouts') return self::printFamily($u,$d['family']??'',$action);
        if (in_array($resource,['employees','wisdom','field-templates','announcements'],true)) {
            if ($resource==='announcements') return $u->allows('hr',$action) || $u->allows('admin',$action) || in_array((string)$u->employee_id,array_map('strval',$d['recipientIds']??[]),true) || ($d['targetType']??'')==='all';
            return true;
        }
        if (in_array($resource,['work-presence','field-work'],true)) return self::own($u,$d) || ($u->manages($d['department']??'') && ($d['department']??''));
        if ($resource==='tasks') {
            if (self::taskOwn($u,$d)) return true;
            $scope=$u->taskScope();
            if ($scope==='mine') return false;
            if ($scope==='department') return (($d['department']??'')===$u->department||$u->manages($d['department']??''))&&$u->allows($m,$action);
            return $u->allows($m,$action)||$u->globalPermission('viewAllTasks');
        }
        if ($resource==='customers') return Task::where('employee_id',$u->employee_id)->where('data->customer',$d['name']??'')->exists();
        if ($resource==='payments' && $u->allows('hr',$action)) return (FinanceRules::document($d['docId']??'')['kind']??'')==='employee';
        if ($resource==='financial-documents' && ($d['kind']??'')==='employee' && $u->allows('hr',$action)) return true;
        if (in_array($resource,['hr-requests','hr-approvals','employment-contracts','offboarding','recognition','payroll-hours'],true)) {
            if (self::own($u,$d)) return true;
            if (in_array($resource,['hr-requests','hr-approvals'],true)&&\App\Services\R34\Hierarchy::wasHrApprover($u,$d)) return true;
            if ($u->allows('hr',$action)) return true;
            if (in_array($resource,['employment-contracts','payroll-hours'],true)) return $resource==='payroll-hours'&&$u->allows('accounting','reports');
            if ($resource==='offboarding'&&($u->allows('logistics','approve')||$u->allows('accounting','approve')||$u->allows('admin','approve'))) return true;
            $dep=$d['department']??Employee::find($d['employeeId']??'')?->department;
            if ($dep && $u->manages($dep)) return true;
            if (in_array($resource,['hr-requests','hr-approvals','offboarding'],true)) {
                $route=$d['route']??$d['levels']??[];
                return $u->allows($m,'approvals') || in_array($u->department,$route,true) && $u->allows(User::departmentApp($u->department),'approve');
            }
            return false;
        }
        if ($resource==='executive-reports') return $u->allows('admin','reports') || $u->manages($d['department']??'');
        if ($resource==='support-requests') return $u->allows($m,$action)||($d['sourceDepartment']??'')===$u->department||($d['createdById']??'')===$u->id;
        if (in_array($resource,['warehouses','stock-items','suppliers'],true)) return $u->allows($m,$action)||$u->allows('logistics',$action)||$u->allows('purchases',$action)||$u->allows('accounting',$action);
        if (in_array($u->role?->data['scope']??'',['department','department_limited'],true)&&!empty($d['department'])&&$d['department']!==$u->department&&!$u->manages($d['department'])) return false;
        return $u->allows($m,$action);
    }
    public static function settingsReadable(User $u, string $key): bool {
        return $u->active && ($key === 'department-managers' || self::settings($u, $key));
    }
    public static function settings(User $u,string $key): bool {
        if (!$u->active) return false;
        if ($u->root()) return true;
        if (in_array($key,['system','ui-controls'],true)) return $u->globalPermission('systemCustomization') || $u->globalPermission('appearanceSettings');
        if (in_array($key,['finance','finance-chart','finance-links','purchasing','executive-report-reviews'],true)) return $u->administration();
        if ($key==='print-defaults') return $u->allows('accounting','edit')||$u->allows('hr','edit')||$u->allows('sales','edit')||$u->globalPermission('systemCustomization');
        return $u->allows(config('mosh.spec.settings.'.$key.'.module',''),'edit');
    }
    public static function printFamily(User $u,string $family,string $action='edit'): bool {
        if ($u->root()||$u->globalPermission('systemCustomization')) return true;
        $module=match($family) {
            'عرض مبيعات','quotation','sales','service-contract' => 'sales',
            'قسيمة راتب','كشف رواتب','employment-contract','payroll','payslip','hr' => 'hr',
            default => 'accounting',
        };
        return $u->allows($module,$action);
    }
    public static function document(User $u,string $resource,array $d): array {
        if ($resource==='employees' && !$u->root() && !($u->allows('hr','view') && $u->allows('hr','details')) && (string)$u->employee_id!==(string)($d['id']??'')) {
            return Arr::only($d,['id','_version','employeeNo','name','department','jobTitle','employmentStatus','active','directManager','isManager','fieldWorkTemplateId','fieldTemplateId']);
        }
        return $d;
    }
    public static function write(User $u,string $resource,string $action,?array $old,array $new): void {
        if ($resource==='stock-movements'&&\App\Services\R34\CommandContext::permits('damage',(string)($new['id']??''))) return;
        if ($u->root()) return;
        if ($resource==='employment-contracts') { abort_unless(\App\Services\R29\HrWorkflow::hr($u,$action),403,'العقود للموارد البشرية فقط.'); return; }
        if ($resource==='announcements') {
            if ($action==='create') {abort_unless($u->allows('hr','create')||$u->allows('admin','create'),403);return;}
            if ($action==='edit' && ($u->allows('hr','edit') ||
                ($u->allows('admin','edit') && (string)($old['createdById']??'')===(string)$u->id))) return;
        }
        if ($resource==='print-layouts') {
            abort_unless(self::printFamily($u,($new['family']??$old['family']??''),$action)&&(!$old||self::printFamily($u,$old['family'],$action)),403);return;
        }
        $d=$old??$new; $module=self::module($resource,$d);
        $changed=array_keys(array_filter($new,fn($v,$k)=>!array_key_exists($k,$old??[])||$v!==$old[$k],ARRAY_FILTER_USE_BOTH));
        $changed=array_diff($changed,['id','_version','updatedAt','createdAt','createdBy','history','auditTrail']);
        if ($resource === 'customers') {
            if ($action === 'create') {
                abort_unless($u->customerPermission('create'), 403, 'لا تملك صلاحية إضافة عميل.');
                return;
            }
            if ($action === 'delete') {
                abort_unless($u->customerPermission('edit'), 403, 'لا تملك صلاحية حذف عميل.');
                return;
            }
            // Marketing may still update its own discovery/plans without altering the master card.
            if ($action === 'edit' && !array_diff($changed, ['marketingProfile'])) {
                abort_unless($u->allows('marketing', 'edit') || $u->customerPermission('edit'), 403);
                return;
            }
            // Accounting keeps its ability to update only accounting-owned fields.
            if ($action === 'edit' && !array_diff($changed, ['currency','paymentDays','taxNumber','accountCode22'])
                && $u->allows('accounting', 'edit')) return;
            abort_unless($u->customerPermission('edit'), 403, 'تعديل بيانات العميل محصور بالحسابات المخولة.');
            return;
        }
        if ($resource==='financial-documents' && ($d['kind']??'')==='employee' && $u->allows('hr',$action)) return;
        if (in_array($resource,['stock-items','stock-movements'],true) && ($u->allows('logistics',$action)||\App\Models\Warehouse::where('data->managerId',$u->employee_id)->exists())) return;
        if ($resource==='support-requests' && $action==='edit') {
            $phase=$old['operation18']['phase']??'new';
            if (($old['createdById']??'')===($u->employee_id?:$u->id) && in_array($phase,['new','draft','pricing','returned'],true)) return;
            if (in_array($phase,['review','deferred'],true) && ($u->administration()||in_array($u->employee_id?:$u->id,\App\Models\Setting::valueOf('purchasing'),true))) return;
            if (($new['operation18']['managerApproval']??null)!==($old['operation18']['managerApproval']??null)&&$u->manages($old['sourceDepartment']??'')) return;
        }
        if (in_array($resource,['work-presence','field-work'],true)) { abort_unless(self::own($u,$d),403); return; }
        if ($resource==='employees' && !$u->allows('hr',$action)) {
            abort_unless($action==='edit' && (string)$u->employee_id===(string)($d['id']??'') && !array_diff($changed,['hrNoticeArchive']),403); return;
        }
        if ($resource==='tasks') {
            if ($action==='create') { abort_unless($u->globalPermission('createTasks')||$u->allows($module,'create'),403); return; }
            abort_unless(self::readable($u,$resource,$d),403);
            if ($action==='delete') { abort_unless($u->globalPermission('deleteTasks') && $u->allows($module,'delete'),403); return; }
            $worker=['employeeState','status','workTiming','workChat','attachments','notes','completedAt','completedBy','returnedForRevision','reviewManager'];
            if (self::taskOwn($u,$d) && !array_diff($changed,$worker)) {
                if (array_intersect($changed,['employeeState','status','workTiming','returnedForRevision'])) abort_unless($u->allows($module,'status'),403);
                if (in_array('attachments',$changed,true)) abort_unless($u->allows($module,'attachments'),403);
                abort_if(in_array($new['status']??'',['awaiting_invoice','invoiced','no_invoice','cancelled'],true),403); return;
            }
            abort_unless($u->allows($module,'edit')||$u->manages($d['department']??''),403);
            if (array_intersect($changed,['department','assignee','assigneeId','assignmentMode','sharedDistributionRoot'])) abort_unless($u->allows($module,'assign')||$u->manages($d['department']),403);
            return;
        }
        if (in_array($resource,['hr-requests','hr-approvals'],true)) {
            if ($action==='create') { abort_unless(self::own($u,$new)||$u->allows('hr','create'),403); return; }
            if (!array_diff($changed,['finalSeenAt','finalSeenBy','resultSeenAt','resultSeenBy','employeeSeenAt','employeeSeenBy'])) { abort_unless(self::own($u,$d),403); return; }
            if (\App\Services\R34\Hierarchy::currentHrApprover($d)) {abort_unless(\App\Services\R34\Hierarchy::isHrApprover($u,$d)&&self::readable($u,$resource,$d,'details'),403,'هذا الطلب مخصص للمسؤول المحدد في سلسلة الاعتماد.');return;}
            $stage=($d['route']??$d['levels']??[])[$d['stageIndex']??$d['currentLevel']??0]??'';
            $dep=$d['department']??Employee::find($d['employeeId']??'')?->department??'';
            $manager=in_array($stage,['المدير المباشر','مدير القسم','مدير القسم الحالي','مدير القسم الجديد'],true);
            if ($stage==='مدير القسم الجديد') $dep=$d['data']['target']??'';
            $stageModule=User::departmentApp(is_array($stage)?($stage['department']??''):$stage);
            $stageModule=['مسؤول الموارد البشرية'=>'hr','المدير التنفيذي / الإدارة العليا'=>'admin'][$stageModule]??$stageModule;
            abort_unless($manager?$u->manages($dep):$u->allows($stageModule,'approve'),403,'لا يمكنك اعتماد هذه المرحلة.'); return;
        }
        if ($resource==='announcements' && $action==='edit' && !array_diff($changed,['readBy','readAtBy'])) { abort_unless(self::readable($u,$resource,$d),403); return; }
        if ($resource==='employment-contracts' && $action==='edit' && !array_diff($changed,['signatures','status'])) { abort_unless(self::own($u,$d)||$u->allows('hr','approve'),403); return; }
        if ($resource==='executive-reports' && $action==='create') { abort_unless($u->manages($d['department']??'')||$u->allows('admin','create'),403); return; }
        if ($resource==='support-requests' && $action==='create') { abort_unless(($new['sourceDepartment']??'')===$u->department||$u->allows($module,'create'),403); return; }
        abort_unless($u->allows($module,$action),403,'لا تملك صلاحية تنفيذ هذا الإجراء.');
    }
}

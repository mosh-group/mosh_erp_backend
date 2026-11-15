<?php
namespace App\Support;
use App\Models\{User,Task,Employee,HrRequest,EmploymentContract,Quotation,Invoice,FinancialDocument,Announcement,Setting,Recognition,OffboardingCase};
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class BusinessRules
{
    public static function validate(User $actor,string $resource,?array $old,array &$d): void {
        foreach (['startDate','endDate','hireDate','birthDate','date','dueDate','lastWorkDay','openingDate'] as $key) if (!empty($d[$key])) Validator::make($d,[$key=>'date_format:Y-m-d'])->validate();
        if (!empty($d['startDate'])&&!empty($d['endDate'])) self::need($d['endDate']>=$d['startDate'],'تاريخ النهاية يسبق البداية.');
        if (in_array($resource,['customers','services','salespeople','sales-teams','employees','suppliers','wisdom'],true)) {
            $key=$resource==='wisdom'?'text':'name';$d[$key]=trim((string)$d[$key]);$class=Records::model($resource);
            $norm=fn($v)=>mb_strtolower(preg_replace('/\s+/u',' ',trim((string)$v)));
            self::need(!$class::where('id','!=',(string)$d['id'])->get()->contains(fn($r)=>$norm($r->data[$key]??'')===$norm($d[$key])),'الاسم أو النص مسجل مسبقاً.');
        }
        if (in_array($resource,['customers','suppliers'],true)) Validator::make($d,['paymentDays'=>'nullable|numeric|between:0,3650','secondaryContacts.*.name'=>'required|string|max:200','secondaryContacts.*.email'=>'nullable|email:rfc'])->validate();
        switch ($resource) {
            case 'tasks': self::task($actor,$old,$d); break;
            case 'employees': self::employee($actor,$old,$d); break;
            case 'hr-requests': self::hrRequest($actor,$old,$d); break;
            case 'hr-approvals': self::legacyApproval($actor,$old,$d); break;
            case 'customers': self::marketing($actor,$old,$d); break;
            case 'requisitions': self::requisition($actor,$d); break;
            case 'offboarding':
                foreach (['hr'=>'hr','logistics'=>'logistics','accounting'=>'accounting','system'=>'admin'] as $key=>$mod) if (($d[$key]??false)!==($old[$key]??false)) abort_unless($actor->root()||$actor->allows($mod,'approve'),403);
                if (($d['status']??'')==='closed') foreach (['hr','logistics','accounting','system'] as $k) self::need(!empty($d[$k]),'أكمل إخلاء الطرف من جميع الجهات.');
                break;
            case 'employment-contracts': self::contract($actor,$old,$d); break;
            case 'announcements': self::announcement($actor,$old,$d); break;
            case 'executive-reports':
                if (!$old) { $d['sender']=$actor->name; $d['status']='new'; }
                else self::unchanged($old,$d,['sender','summary','title','department','type','period','executiveNeed','priority']);
                if ($old&&(($d['review']??null)!==($old['review']??null)||($d['status']??'')!==($old['status']??''))) { abort_unless($actor->administration(),403); $d['reviewedBy']=$actor->name; $d['reviewedAt']=now()->toIso8601String(); }
                break;
            case 'admin-decisions': case 'admin-meetings':
                if (!empty($d['time'])) Validator::make($d,['time'=>'date_format:H:i'])->validate(); break;
            case 'work-presence':
                Validator::make($d,['mode'=>'required|in:active,break,off','noWork'=>'sometimes|boolean'])->validate();
                if ($d['mode']!=='active') $d['noWork']=false;
                $now=now()->getTimestampMs();
                foreach (['startedAt','breakAt','endedAt','noWorkAt'] as $key) $d[$key]=$old[$key]??0;
                if (!empty($d['noWork'])&&empty($old['noWork'])) $d['noWorkAt']=$now;
                if (($d['mode']??'')!==($old['mode']??'')) {
                    if ($d['mode']==='active') { $d['startedAt']=($old['mode']??'off')==='off'?$now:($old['startedAt']??$now);$d['breakAt']=0;$d['endedAt']=0; }
                    if ($d['mode']==='break') { self::need(($old['mode']??'')==='active','ابدأ العمل أولاً.'); $d['breakAt']=$now; }
                    if ($d['mode']==='off') $d['endedAt']=$now;
                }
                break;
            case 'field-work':
                self::fieldWork($actor,$old,$d);
                if (($d['currentStatus']??'')!==($old['currentStatus']??'')) self::need(($d['workState']??'')==='active'||($d['workState']??'')==='working','ابدأ العمل قبل تسجيل الحالة الميدانية.');
                break;
            case 'salespeople':
                if (!empty($d['salesTeam'])) self::need(\App\Models\SalesTeam::whereKey((string)$d['salesTeam'])->orWhere('data->name',$d['salesTeam'])->exists(),'فريق المبيعات غير موجود.'); break;
            case 'sales-teams':
                if (!empty($d['leader'])) self::need(\App\Models\Salesperson::whereKey((string)$d['leader'])->orWhere('data->name',$d['leader'])->exists(),'قائد الفريق غير موجود.');
                $memberIds=array_map(fn($x)=>is_array($x)?($x['id']??''):$x,$d['members']??[]);
                foreach ($memberIds as $id) self::need(\App\Models\Salesperson::whereKey((string)$id)->orWhere('data->name',(string)$id)->exists(),'عضو فريق المبيعات غير موجود.');
                self::need(count(array_unique($memberIds))===count($memberIds),'عضو الفريق مكرر.'); break;
            case 'quotation-templates':
                Validator::make($d,['style'=>'sometimes|in:official,compact,detailed','validityDays'=>'nullable|integer|min:0'])->validate(); break;
            case 'service-contracts':
                Validator::make($d,['status'=>'sometimes|in:draft,active,expired,cancelled','taxRate'=>'nullable|numeric|between:0,100','lines.*.service'=>'required|string|max:500','lines.*.description'=>'required|string|max:20000','lines.*.qty'=>'required|numeric|gt:0','lines.*.price'=>'required|numeric|min:0'])->validate();
                if (($d['status']??'')==='active') self::need(count($d['lines']??[])>0,'أضف بنود العقد.'); break;
            case 'field-templates':
                foreach (['statuses','activities'] as $key) {Validator::make($d,[$key=>'sometimes|array|max:500', $key.'.*.id'=>'required|string|distinct|max:100',$key.'.*.label'=>'required|string|max:300',$key.'.*.active'=>'sometimes|boolean'])->validate();} break;
            case 'print-layouts':
                Validator::make($d,['size'=>'required|numeric|between:9,24','width'=>'required|numeric|between:148,420','height'=>'required|numeric|between:148,594',
                    'margin'=>'required|numeric|between:5,35','padding'=>'required|numeric|between:3,20','font'=>['required','string','regex:/^[\pL\pN ,_-]{1,100}$/u'],'color'=>['required','regex:/^#[0-9a-fA-F]{6}$/'],'line'=>['required','regex:/^#[0-9a-fA-F]{6}$/'],'headBg'=>['required','regex:/^#[0-9a-fA-F]{6}$/'],'blocks.*.align'=>'required|in:left,center,right',
                    'blocks.*.gap'=>'numeric|between:0,100','blocks.*.size'=>['numeric',fn($k,$v,$fail)=>((float)$v===0.0||($v>=8&&$v<=48))?null:$fail('حجم الخط صفر أو من 8 إلى 48.')]])->validate();
                self::need($d['width']>2*$d['margin']+70,'مساحة الورقة غير كافية.'); break;
            case 'quotations':
                Validator::make($d,['status'=>'sometimes|in:draft,sent,order,cancel'])->validate();
                foreach ($d['lines']??[] as $line) {
                    Validator::make($line,['type'=>'sometimes|in:product,section,note'])->validate();
                    Validator::make($line,($line['type']??'product')==='product'?['product'=>'required|string|max:500','qty'=>'required|numeric|gt:0','price'=>'required|numeric|min:0','cost'=>'sometimes|numeric|min:0','discount'=>'numeric|between:0,100','tax'=>'numeric|between:0,100']:['description'=>'required|string|max:20000'])->validate();
                }
                Validator::make($d,['optional'=>'sometimes|array','optional.*.product'=>'required|string|max:500','optional.*.qty'=>'required|numeric|gt:0','optional.*.price'=>'required|numeric|min:0'])->validate();
                if (isset($d['followupNotes'])) {
                    $known=array_column($old['followupNotes']??[],null,'id');
                    foreach ($d['followupNotes'] as &$note) {
                        Validator::make($note,['id'=>'required','text'=>'required|string|max:20000','kind'=>'required|string|max:80'])->validate();
                        if (isset($known[$note['id']])) $note=$known[$note['id']];
                        else {$note['by']=$actor->name;$note['time']=now()->toIso8601String();}
                    }unset($note);
                }
                if (($old['status']??'')==='order' && ($d['lines']??[])!==($old['lines']??[])) self::need(!Invoice::where('data->source',$old['number']??'')->exists(),'العرض مرتبط بفاتورة.');
                break;
        }
        if (in_array($resource,['warehouses','stock-items','stock-movements','reservations','support-requests','suppliers'],true)) StockRules::validate($actor,$resource,$old,$d);
        if (in_array($resource,['invoices','financial-documents','cash-accounts','payments','journal-entries','payroll-hours','payroll-runs'],true)) FinanceRules::validate($actor,$resource,$old,$d);
    }
    public static function need(bool $condition,string $message): void { abort_unless($condition,422,$message); }
    private static function unchanged(array $old,array $d,array $fields): void {
        foreach ($fields as $key) self::need(($old[$key]??null)===($d[$key]??null),'لا يمكن تعديل الحقل المعتمد: '.$key);
    }
    private static function employee(User $a,?array $old,array &$d,bool $derived=false): void {
        \App\Services\R29\Guards::employee($a,$old,$d);
        self::need(empty($old['archivedAt']), 'هذا الموظف مؤرشف؛ استعده من الأرشيف قبل تعديل ملفه.');
        foreach(['archivedAt','archivedBy'] as $field) self::need(($d[$field]??null)===($old[$field]??null),'الأرشفة من الإجراء المخصص فقط.');
        if ($old&&!$a->root()&&!$a->allows('hr','edit')&&(string)$a->employee_id===(string)$d['id']) {
            $before=$old['hrNoticeArchive']??[];$after=$d['hrNoticeArchive']??[];
            self::need(is_array($after)&&count($before)===count($after),'لا يمكن حذف أو إنشاء نسخ تنبيهات الموظف.');
            $known=array_column($before,null,'id');
            foreach ($after as $copy) {
                $previous=$known[$copy['id']??'']??null;self::need((bool)$previous,'نسخة تنبيه غير صالحة.');
                foreach (array_unique(array_merge(array_keys($previous),array_keys($copy))) as $key) if (!in_array($key,['readAt','readBy'],true)) self::need(($copy[$key]??null)===($previous[$key]??null),'محتوى التنبيه لا يعدل بواسطة المستلم.');
            }
        }

        if ((string)$a->employee_id===(string)$d['id']) self::need(($d['active']??true)&&($d['employmentStatus']??'active')==='active','لا يمكنك تعطيل ملف حسابك الحالي.');
        if (!$derived) {
            $custody=fn($data)=>array_values(array_filter($data['assets']??[],fn($x)=>in_array(($x['source']??''),['warehouse18','it31'],true)||!empty($x['movementId'])));
            self::need($custody($old??[])===$custody($d),'عهد المستودع وIT تعدّل من سجلاتها الأصلية فقط.');
            foreach (['leaveRecords','disciplinaryRecords','rewardRecords'] as $field) {
                $previous=array_column($old[$field]??[],null,'id');$current=array_column($d[$field]??[],null,'id');
                foreach ($previous as $id=>$record) if (!empty($record['approvalId'])) self::need(isset($current[$id])&&($current[$id]['approvalId']??'')===$record['approvalId'],'لا تحذف أو تفصل سجلاً مرتبطاً باعتماد.');
                foreach ($current as $id=>$record) {
                    if ($field==='disciplinaryRecords') Validator::make($record,['reason'=>'required|string|max:20000','level'=>'required|string|max:100'])->validate();
                    $before=$previous[$id]??[];
                    if (($record['status']??null)!==($before['status']??null)) {
                        abort_unless($a->allows('hr','edit'),403);
                        if (!empty($record['approvalId'])&&!in_array($record['status']??'',['pending','requested'],true)) {
                            $approval=\App\Models\HrApproval::find($record['approvalId']);
                            $expected=($approval?->data['status']??'')==='approved'?($field==='disciplinaryRecords'?'active':'approved'):($approval?->data['status']??'');
                            self::need($approval&&(string)($approval->data['employeeId']??'')===(string)$d['id']&&(string)($approval->data['sourceId']??'')===(string)$id&&$expected===($record['status']??''),'حالة السجل تتطلب قرار الاعتماد المرتبط.');
                        }
                    }
                    if (($record['paymentStatus']??null)!==($before['paymentStatus']??null)&&!empty($record['paymentStatus'])) abort_unless($a->allows('accounting','edit'),403);
                }
            }
        }
        Validator::make($d,['zktecoEnrollmentNumber'=>'nullable|string|max:100','zktecoExempt'=>'nullable|boolean'])->validate();
        if (($d['employmentStatus']??'active')!=='draft' && config('zkteco.enabled') && (config('zkteco.require_employee_mapping') || array_key_exists('zktecoEnrollmentNumber',$d) || array_key_exists('zktecoExempt',$d))) {
            $enrollment=trim((string)($d['zktecoEnrollmentNumber']??'')); $exempt=(bool)($d['zktecoExempt']??false);
            self::need($exempt || $enrollment!=='','حدد رقم تسجيل ZKTeco أو اختر إعفاء الموظف من البصمة.');
            self::need(!($exempt && $enrollment!==''),'لا يمكن الجمع بين إعفاء ZKTeco ورقم التسجيل.');
        }
        Validator::make($d,['workdayHours'=>'nullable|numeric|gt:0|max:24','plannedTimeCoefficient'=>'nullable|numeric|gt:0|max:100',
            'annualLeaveDays'=>'nullable|numeric|min:0|max:366','sickLeaveDays'=>'nullable|numeric|min:0|max:366',
            'salary'=>'nullable|numeric|min:0','monthlySalary'=>'nullable|numeric|min:0','evaluationRecords.*.score'=>'numeric|between:0,100',
            'documents.*.name'=>'sometimes|string|max:250'])->validate();
        Validator::make($d,['employmentStatus'=>'sometimes|in:draft,active,inactive,resigned','attendanceRecords.*.date'=>'required|date_format:Y-m-d','attendanceRecords.*.inTime'=>'nullable|date_format:H:i','attendanceRecords.*.outTime'=>'nullable|date_format:H:i','attendanceRecords.*.breakMinutes'=>'nullable|numeric|between:0,1440'])->validate();
        if (($d['employmentStatus']??'')==='resigned') Validator::make($d,['resignationDate'=>'required|date_format:Y-m-d','resignationReason'=>'required|string'])->validate();
        if (User::where('employee_id',(string)$d['id'])->where('is_root',true)->exists()) self::need(($d['active']??true)&&($d['employmentStatus']??'active')==='active','ملف الحساب الأساسي لا يعطل.');
        $attendance=[];foreach ($d['attendanceRecords']??[] as $record) {
            if (($record['status']??'')==='cancelled') continue;
            if (($record['source']??'')==='zkteco' && ($record['status']??'')==='overridden') continue;
            $key=$record['date'].'|'.($record['inTime']??$record['checkIn']??'').'|'.($record['outTime']??$record['checkOut']??'');
            self::need(!isset($attendance[$key]),'سجل الدوام مكرر.');$attendance[$key]=true;
        }
        foreach ($d['leaveRecords']??[] as $i=>$leave) {
            Validator::make($leave,['startDate'=>'required|date_format:Y-m-d','endDate'=>'required|date_format:Y-m-d|after_or_equal:startDate'])->validate();
            if (($leave['status']??'')==='rejected') continue;
            foreach (array_slice($d['leaveRecords'],0,$i) as $other) if (($other['status']??'')!=='rejected') self::need($leave['startDate']>($other['endDate']??'')||$leave['endDate']<($other['startDate']??'')||(($leave['type']??'')==='ساعية'&&($other['type']??'')==='ساعية'&&(($leave['toTime']??'')<=($other['fromTime']??'')||($leave['fromTime']??'')>=($other['toTime']??''))),'توجد إجازة متداخلة.');
        }
    }
    private static function task(User $a,?array $old,array &$d): void {
        \App\Services\R34\TaskPolicy::apply($a,$old,$d);
        R19TaskPriority::apply($a,$old,$d);
        $statuses=['new','in_progress','completed','awaiting_invoice','invoiced','cancelled','archived_invoiced','archived_no_invoice','shared_container'];
        self::need(in_array($d['status']??'in_progress',$statuses,true),'حالة المهمة غير صالحة.');
        if ($id=$d['assigneeId']??'') {
            $p=Employee::find($id); self::need((bool)$p,'الموظف غير موجود.');
            if (!$old||($old['assigneeId']??'')!==$id) self::need(($p->data['active']??true)!==false&&($p->data['employmentStatus']??'active')==='active','الموظف غير متاح للتكليف.');
            self::need(($p->data['department']??'')===($d['department']??'')||(string)(Setting::valueOf('department-managers')[$d['department']]??'')===(string)$p->id,'الموظف ليس في قسم المهمة.');
        }
        if (!empty($d['parentTaskId'])) {
            self::need((string)$d['id']!==(string)$d['parentTaskId'],'المهمة لا تكون فرعاً من نفسها.');
            $parent=Task::find($d['parentTaskId']); $seen=[(string)$d['id']];
            while ($parent) { self::need(!in_array((string)$parent->id,$seen,true),'دورة غير صالحة في المهام.'); $seen[]=(string)$parent->id; $parent=empty($parent->data['parentTaskId'])?null:Task::find($parent->data['parentTaskId']); }
        }
        if (!empty($old['sharedDistributionChild'])) self::unchanged($old,$d,['sharedDistributionChild','sharedRootTaskId','sharedNodeType']);
        $state=$d['employeeState']??'new';$prior=$old['employeeState']??'new';
        self::need(in_array($state,['new','received','working','paused','submitted','finished'],true),'حالة الموظف غير صالحة.');
        if (!$old) self::need($state==='new','المهمة الجديدة تبدأ قبل الاستلام.');
        if ($old&&$state!==$prior) {
            $reassigned=($d['assigneeId']??'')!==($old['assigneeId']??'')||($d['department']??'')!==($old['department']??'');
            $restored=str_starts_with($old['status']??'','archived_')&&($d['status']??'')==='in_progress';
            if ($state==='new'&&($reassigned||$restored)) abort_unless($a->allows(RecordAccess::module('tasks',$old),'assign'),403);
            elseif ($prior==='submitted'&&$state==='paused'&&!empty($d['returnedForRevision'])) {abort_unless($a->allows(RecordAccess::module('tasks',$old),'approve')||$a->manages($old['department']??''),403);self::need(($d['assigneeId']??'')===($old['assigneeId']??''),'أعد العمل إلى الموظف المكلف نفسه.');}
            elseif ($state==='finished') {
                abort_unless($a->allows(RecordAccess::module('tasks',$old),'approve')||$a->manages($old['department']??''),403);
                self::need($prior==='submitted'||!empty($old['sharedDistributionRoot'])||!empty($old['sharedTeamContainer']),'سلّم المهمة للمراجعة أولاً.');
            } else {
                abort_unless((string)$a->employee_id===(string)($old['assigneeId']??'')&&$a->allows(RecordAccess::module('tasks',$old),'status'),403,'الإجراء للموظف المكلف فقط.');
                self::need(in_array($state,['new'=>['received','working'],'received'=>['working'],'working'=>['paused','submitted'],'paused'=>['working','submitted']][$prior]??[],true),'انتقال حالة الموظف غير صالح.');
            }
        }
        TaskExecutionClock::apply($a,$old,$d);
        $running=!empty($d['workTiming']['activeStartedAt']);
        if (($d['employeeState']??'')==='submitted') {
            self::need(!$running,'أوقف مؤقت العمل قبل التسليم.');
            $p=Employee::find($d['assigneeId']??'');$manager=Employee::find(Setting::valueOf('department-managers')[$d['department']]??'');
            $d['reviewManager']=$manager?->data['name']??(($p?->data['department']??'')===($d['department']??'')?($p?->data['directManager']??''):'');
        }
        if (isset($d['workChat'])) {
            $known=array_column($old['workChat']??[],null,'id');
            foreach ($d['workChat'] as &$message) {
                if(empty($message['id']))continue; // legacy entry preserved by TaskPolicy
                if (isset($known[$message['id']??''])) {if(!\App\Services\R34\CommandContext::permits('chat',(string)$d['id']))$message=$known[$message['id']];continue;}
                $message['by']=$a->name;$message['byId']=(string)$a->id;$message['time']=now()->toIso8601String();
            }unset($message);
        }

        if ($old && ($d['status']??'')!==($old['status']??'')) {
            if ($d['status']==='awaiting_invoice'&&(!empty($old['sharedDistributionRoot'])||Task::where('parent_id',(string)$d['id'])->exists())) abort_unless($a->allows('accounting','approve'),403);
            if (in_array($d['status'],['awaiting_invoice','completed'],true)) {
                abort_unless($a->root()||$a->manages($old['department']??'')||$a->allows(RecordAccess::module('tasks',$old),'approve'),403);
                // Child completion is checked after the atomic batch, independent of operation order.
            }
            if (str_starts_with($d['status'],'archived_')) {
                abort_unless($a->allows('accounting','approve'),403); self::need(($old['status']??'')==='awaiting_invoice','المهمة ليست جاهزة للفوترة.');
                if ($d['status']==='archived_invoiced') {
                    $invoice=FinanceRules::document($d['finance21InvoiceId']??'');
                    self::need(($invoice&&$invoice['status']==='posted'&&$invoice['party']===$d['customer']&&empty($invoice['opening22']))||!empty($d['finance21ExternalInvoice']),'اربط فاتورة عميل معتمدة أو مرجع فاتورة خارجية.');
                } else self::need(!empty($d['finance21Reason']),'اكتب سبب عدم الفوترة.');
            }
        }
    }
    private static function hrRequest(User $a,?array $old,array &$d): void {
        $routes=json_decode(file_get_contents(resource_path('hr-routes.json')),true);
        self::need(isset($routes[$d['type']]),'نوع طلب الموظف غير معروف.');
        $p=Employee::find($d['employeeId']); self::need((bool)$p,'الموظف غير موجود.');
        if (!$old) {
            $d['route']=$routes[$d['type']]; $d['stageIndex']=0; $d['status']='pending'; $d['employeeName']=$p->data['name']; $d['department']=$p->data['department'];
            $d['createdDate']=now()->toDateString();
            $d+=['managerDecision'=>'','managerDecisionBy'=>'','managerDecisionAt'=>'','managerNote'=>''];
            \App\Services\R34\Hierarchy::prepareHr($a,null,$d);
        } else {
            \App\Services\R34\Hierarchy::prepareHr($a,$old,$d);
            \App\Services\R34\Hierarchy::guardHrDecision($a,$old,$d);
            self::unchanged($old,$d,['type','employeeId','reason','data','route']);
            if (($d['stageIndex']??0)!==($old['stageIndex']??0)||$d['status']!==$old['status']) {
                self::need($old['status']==='pending','الطلب مغلق.');
                $last=($old['stageIndex']??0)>=count($old['route'])-1;
                self::need(in_array($d['status'],['pending','approved','rejected','cancelled'],true),'حالة غير صالحة.');
                if ($d['status']==='approved') self::need($last,'لا يمكن تجاوز مرحلة اعتماد.');
                if ($d['status']==='pending') self::need(($d['stageIndex']??0)===($old['stageIndex']??0)+1&&!$last,'مرحلة غير صالحة.');
                if ($d['status']==='rejected') { $entry=end($d['history']); self::need(!empty($entry['note']),'سبب الرفض مطلوب.'); }
                if ($d['status']!=='pending') $d['completedAt']=now()->toIso8601String();
            }
        }
        if (in_array($d['type'],['annual_leave','sick_leave'],true)) Validator::make($d['data'],['startDate'=>'required|date_format:Y-m-d','endDate'=>'required|date_format:Y-m-d|after_or_equal:startDate'])->validate();
        if (in_array($d['type'],['advance','reward','deduction'],true)) Validator::make($d['data'],['amount'=>'required|numeric|gt:0|max:900000000000','currency'=>'required|in:USD,SYP,EUR,AED,SAR,LBP,JOD,TRY,EGP'])->validate();
        if (in_array($d['type'],['hourly_leave','attendance_correction'],true)) Validator::make($d['data'],['date'=>'required|date_format:Y-m-d','fromTime'=>'required|date_format:H:i','toTime'=>'required|date_format:H:i|after:fromTime'])->validate();
        $data=$d['data']??[];
        if ($d['type']==='transfer') self::need(in_array($data['target']??'',self::departments(),true),'القسم المطلوب غير موجود.');
        if (in_array($d['type'],['promotion','recruitment'],true)) Validator::make($data,['jobTitle'=>'required|string|max:500']+($d['type']==='recruitment'?['headcount'=>'required|integer|between:1,1000']:[]))->validate();
        if ($d['type']==='salary_certificate') Validator::make($data,['language'=>'required|string|max:100','target'=>'required|string|max:500'])->validate();
        if ($d['type']==='document_update') {
            Validator::make($data,['docType'=>'required|string|max:200'])->validate();
            $files=$data['files']??$data['attachments']??$data['documents']??[];
            self::need(is_array($files)&&count($files)>0,'ارفع الوثائق المطلوبة.');
            foreach ($files as $file) {
                $key=is_array($file)?($file['key']??$file['storageKey']??''):'';
                $upload=\App\Models\StoredFile::where('logical_key',$key)->first();
                self::need((bool)$upload,'الوثيقة غير موجودة.');
                if (!$old) abort_unless($upload->owner_id===$a->id,403,'ارفع وثيقة تملكها.');
            }
        }
        if ($d['type']==='termination') Validator::make($data,['endDate'=>'required|date_format:Y-m-d','target'=>'required|string|max:500'])->validate();
        if ($d['type']==='termination') self::need(trim($d['reason']??'')!=='','سبب إنهاء الخدمة مطلوب.');
    }
    private static function contract(User $a,?array $old,array &$d): void {
        Validator::make($d,['salary'=>'required|numeric|min:0','workHours'=>'required|numeric|between:1,24','employeeId'=>'required|exists:employees,id'])->validate();
        if (in_array($d['type'],['مؤقت','تجربة','تدريب'],true)) self::need(!empty($d['endDate']),'تاريخ النهاية مطلوب للعقد المحدد المدة.');
        if (!empty($old['signatures'])) self::unchanged($old,$d,array_diff(array_keys($old),['signatures','status','updatedAt','updatedBy','_version']));
        $d['signatures']??=[];
        foreach ($d['signatures'] as $side=>&$sig) {
            self::need(in_array($side,['employee','employer'],true),'طرف توقيع غير صالح.');
            if (isset($old['signatures'][$side])) { self::need($old['signatures'][$side]===$sig,'لا يمكن استبدال توقيع محفوظ.'); continue; }
            abort_unless($a->root()||$a->allows('hr','approve')||($side==='employee'&&RecordAccess::own($a,$d)),403);
            Validator::make($sig,['name'=>'required|string|max:200','strokes'=>'required|array|min:1|max:1000','strokes.*'=>'array|min:1|max:5000','strokes.*.*'=>'array|size:2','strokes.*.*.0'=>'required|numeric|between:0,600','strokes.*.*.1'=>'required|numeric|between:0,180'])->validate();
            $sig['at']=now()->toIso8601String(); $sig['recordedBy']=$a->name;
        }
        unset($sig);
        foreach ($old['signatures']??[] as $side=>$sig) self::need(isset($d['signatures'][$side]),'لا يمكن حذف توقيع محفوظ.');
        if (($d['status']??'')!=='archived') $d['status']=!empty($d['signatures']['employee'])&&!empty($d['signatures']['employer'])?'signed':(!empty($d['signatures'])?'pending_signature':'draft');
        foreach (['clauses','commonClauses','typeClauses','customClauses','dutyClauses','conditionClauses'] as $field) if (isset($d[$field])) self::clauses($d[$field]);
        if ($old) self::unchanged($old,$d,['parentContractId','version']);
        elseif (!empty($d['parentContractId'])) {
            self::need(empty($d['signatures']),'ابدأ النسخة الجديدة بلا تواقيع.');
            $parent=EmploymentContract::find($d['parentContractId']);self::need($parent&&(string)$parent->data['employeeId']===(string)$d['employeeId'],'أصل العقد غير صالح.');
            self::need(!EmploymentContract::where('data->parentContractId',$parent->id)->exists(),'أنشئت نسخة تالية لهذا العقد.');
            $next=(int)($parent->data['version']??1)+1;
            self::need(!isset($d['version'])||(int)$d['version']===$next,'رقم نسخة العقد غير صالح.');$d['version']=$next;
        } else {self::need(!isset($d['version'])||(int)$d['version']===1,'العقد الجديد يبدأ بالنسخة الأولى.');$d['version']=1;}
    }
    private static function announcement(User $a,?array $old,array &$d): void {
        if (!$old) {
            $people=Employee::all()->filter(fn($p)=>($p->data['active']??true)!==false);
            if (($d['targetType']??'')==='department') $people=$people->filter(fn($p)=>$p->department===($d['targetValue']??''));
            if (($d['targetType']??'')==='employee') $people=$people->filter(fn($p)=>(string)$p->id===(string)($d['targetValue']??''));
            if (($d['targetType']??'')==='employees') {
                $ids=array_values(array_unique(array_filter(array_map('trim',explode(',',(string)($d['targetValue']??''))))));
                self::need(count($ids)>=1 && count($ids)<=1000,'اختر موظفاً أو أكثر.');
                $people=$people->filter(fn($p)=>in_array((string)$p->id,$ids,true));
                self::need(count($people)===count($ids),'يوجد موظف غير متاح ضمن المستلمين.');
            }
            $d['recipientIds']=$people->pluck('id')->values()->all();
            $d['recipientSnapshots']=$people->map(fn($p)=>['id'=>$p->id,'name'=>$p->data['name'],'department'=>$p->department])->values()->all();
            $d['readBy']=[]; $d['readAtBy']=[]; $d['createdById']=$a->id;
        } else {
            $allowed=array_map('strval',$old['readBy']??[]); $mine=(string)$a->employee_id;
            self::need(!array_diff(array_map('strval',$d['readBy']??[]),array_merge($allowed,[$mine])),'لا يمكنك تأكيد قراءة موظف آخر.');
            self::need(!array_diff($allowed,array_map('strval',$d['readBy']??[])),'تأكيدات القراءة محفوظة.');
            foreach ($old['readAtBy']??[] as $id=>$time) self::need(($d['readAtBy'][$id]??null)===$time,'تأكيدات القراءة محفوظة.');
            foreach ($d['readAtBy']??[] as $id=>$time) if ((string)$id!==$mine) self::need(($old['readAtBy'][$id]??null)===$time,'لا يمكنك تغيير قراءة موظف آخر.');
            if (in_array($mine,array_map('strval',$d['readBy']??[]),true)&&!in_array($mine,$allowed,true)) {self::need(in_array($mine,array_map('strval',$old['recipientIds']??[]),true),'للمستلم فقط.');$d['readAtBy'][$mine]=now()->toIso8601String();}
        }
    }
    private static function marketing(User $a,?array $old,array &$d): void {
        if($old&&!\App\Services\R34\CommandContext::permits('customer-archive',(string)$d['id'])){
            abort_if(($old['archiveHistory']??[])!==($d['archiveHistory']??[])||($old['active']??true)!==($d['active']??true),403,'استخدم أرشفة العميل أو إعادة تفعيله مع السبب من ملفه.');
        }
        if (($d['marketingProfile']??null)===($old['marketingProfile']??null)) return;
        abort_unless($a->allows('marketing','edit')||$a->root(),403);
        $oldPlans=array_column($old['marketingProfile']['plans']??[],null,'id');
        $d['marketingProfile']['plans']??=[];
        foreach ($d['marketingProfile']['plans'] as &$p) {
            $b=$oldPlans[$p['id']]??null; $status=$p['status']??'draft';
            self::need(in_array($status,['draft','review','approved','rejected'],true),'حالة خطة غير صالحة.');
            if ($status!=='draft') foreach (['title','objective','audience','message'] as $key) self::need(!empty($p[$key]),'أكمل الخطة قبل إرسالها.');
            if (in_array($status,['approved','rejected'],true)&&$status!==($b['status']??'')) {
                abort_unless($a->root()||$a->allows('marketing','approve')||$a->manages('التسويق'),403);
                self::need(($b['status']??'')==='review','الخطة ليست قيد المراجعة.');
                if ($status==='rejected') self::need(!empty($p['approvalNote']),'سبب رفض الخطة مطلوب.');
                $p['approvedBy']=$a->name; $p['approvedAt']=now()->toIso8601String();
            }
            if (($b['status']??'')==='approved') self::unchanged($b,$p,array_keys($b));
            unset($oldPlans[$p['id']]);
        }
        unset($p);
        foreach ($oldPlans as $p) self::need(in_array($p['status']??'draft',['draft','rejected'],true),'لا يمكن حذف خطة معتمدة أو قيد المراجعة.');
        foreach ($d['marketingProfile']['interactions']??[] as $i) Validator::make($i,['summary'=>'required|string|max:20000','occurredAt'=>'required|date'])->validate();
    }
    private static function requisition(User $a,array &$d): void {
        Validator::make($d,['headcount'=>'required|integer|between:1,1000','status'=>'sometimes|in:open,closed','candidates.*.id'=>'required|distinct','candidates.*.name'=>'required|string|max:200','candidates.*.stage'=>'required|in:screening,interview,accepted,rejected,offer,hired,withdrawn'])->validate();
        foreach ($d['candidates']??[] as $c) {
            if (($c['stage']??'')==='interview'||($c['interviewStatus']??'')==='scheduled') Validator::make($c,['interviewDate'=>'required|date_format:Y-m-d','interviewTime'=>'required|date_format:H:i'])->validate();
            if (($c['stage']??'')==='accepted') self::need(($c['interviewStatus']??'')==='completed','سجّل إتمام المقابلة قبل القبول.');
            if (($c['stage']??'')==='rejected'&&($c['interviewStatus']??'')!=='completed') self::need(!empty($c['notes'])||!empty($c['note']),'سبب عدم القبول مطلوب.');
        }
    }
    private static function fieldWork(User $a,?array $old,array &$d): void {
        $p=Employee::find($d['employeeId']);$tpl=\App\Models\FieldTemplate::find($d['fieldTemplateId']??'');
        self::need($tpl&&($tpl->data['active']??true)&&($p->data['fieldTemplateId']??'')===$tpl->id,'اختر قالب الموظف الميداني الفعال.');
        if (!empty($d['currentStatus'])) self::need(collect($tpl->data['statuses']??[])->contains(fn($s)=>$s['id']===$d['currentStatus']&&($s['active']??true)),'الحالة غير موجودة في القالب.');
        Validator::make($d,['workState'=>'required|in:not_started,working,active,break,no_work,ended','activities'=>'array|max:500'])->validate();
        $known=array_column($old['activities']??[],null,'id');
        foreach ($d['activities'] as &$event) {
            if (isset($known[$event['id']??''])) {$event=$known[$event['id']];continue;}
            $event['at']=now()->toIso8601String();$event['by']=$a->name;$event['fieldTemplateId']=$tpl->id;
            self::need(in_array($event['kind']??'activity',['activity','status','system'],true),'نوع نشاط غير صالح.');
            if (($event['kind']??'activity')==='activity') {
                $activity=collect($tpl->data['activities']??[])->first(fn($x)=>$x['id']===($event['activityId']??'')&&($x['active']??true));
                self::need((bool)$activity,'معرّف النشاط غير موجود في القالب.');$event['label']=$activity['label'];
            }
            if (($event['kind']??'')==='status') self::need(collect($tpl->data['statuses']??[])->contains(fn($x)=>$x['id']===($event['statusId']??'')&&($x['active']??true)),'معرّف الحالة غير موجود في القالب.');
        }unset($event);
        foreach (['startedAt','endedAt','shiftStartAt','statusAt'] as $key) $d[$key]=$old[$key]??'';
        if (($d['workState']??'')!==($old['workState']??'')) {
            if (in_array($d['workState'],['working','active'],true)&&!in_array($old['workState']??'',['working','active','break'],true)) {$d['startedAt']=now()->toIso8601String();$d['shiftStartAt']=$d['startedAt'];$d['endedAt']='';}
            if ($d['workState']==='ended') $d['endedAt']=now()->toIso8601String();
        }
        if (($old['currentStatus']??'')!==($d['currentStatus']??'')) $d['statusAt']=now()->toIso8601String();
    }
    public static function deleting(User $a,string $resource,array $d): void {
        self::need($resource!=='employees','حذف ملف الموظف نهائياً غير مسموح لحماية تاريخه. استخدم إيقاف النشاط أو إنهاء الخدمة؛ لا تُحذف سجلات الموظفين أثناء مزامنة القوائم.');
        if ($resource==='employment-contracts') self::need(empty($d['signatures']),'العقد الموقّع يؤرشف ولا يحذف.');
        if ($resource==='tasks') abort(403,'لا تُحذف المهام نهائياً؛ استخدم إلغاء المهمة بمصادقة التنفيذي للحفاظ على سجلها.');
        if ($resource==='employees') self::need(!User::where('employee_id',(string)$d['id'])->exists(),'الموظف مرتبط بحساب دخول.');
        if ($resource==='quotations') abort_if(Invoice::where('data->finance21->sourceId',(string)$d['id'])->exists()||(!empty($d['number'])&&Invoice::where('data->source',$d['number'])->exists())||Task::where('quotation_id',(string)$d['id'])->exists(),409,'العرض مرتبط بسجل آخر.');
    }
    public static function afterTransaction(User $a,array &$changes,string $tx): void {
        foreach (array_values($changes) as $change) if ($change['resource']==='customers'&&$change['before']&&$change['after']&&$change['before']['name']!==$change['after']['name']) {
            foreach (['quotations','tasks','invoices','service-contracts','stock-items'] as $resource) {
                $class=Records::model($resource);
                foreach ($class::where('data->customer',$change['before']['name'])->get() as $record) {
                    $data=$record->document();$data['customer']=$change['after']['name'];self::derived($a,$resource,$record->id,$data,$changes,$tx);
                }
            }
        }
        foreach (array_values($changes) as $change) {
            if ($change['resource']==='hr-requests' && ($change['after']['status']??'')==='approved' && ($change['before']['status']??'')!=='approved') self::applyHr($a,$change['after'],$changes,$tx);
            if ($change['resource']==='hr-approvals' && in_array($change['after']['status']??'',['approved','rejected'],true) && ($change['before']['status']??'')==='pending') {
                $r=$change['after'];$p=Employee::find($r['employeeId']);if (!$p) continue;$d=$p->document();$approved=$r['status']==='approved';
                $field=['leave_override'=>'leaveRecords','reward'=>'rewardRecords','disciplinary'=>'disciplinaryRecords'][$r['type']]??null;
                if ($field) foreach ($d[$field]??[] as $i=>$rec) if ((string)$rec['id']===(string)$r['sourceId']) $d[$field][$i]['status']=$approved?($field==='disciplinaryRecords'?'active':'approved'):'rejected';
                if ($r['type']==='termination'&&$approved) {$d['employmentStatus']='resigned';$d['active']=false;$d['resignationDate']=$r['payload']['lastWorkDay']??'';$d['resignationReason']=$r['payload']['reason']??'';}
                self::derived($a,'employees',$p->id,$d,$changes,$tx);
            }
        }
        self::workflowEffects($a,$changes,$tx);

        $changedResources = array_values(array_unique(array_map(
            static fn (array $change): string => (string) ($change['resource'] ?? ''),
            $changes
        )));

        $stockResources = ['warehouses', 'stock-items', 'stock-movements', 'reservations'];
        $hasStockChanges = (bool) array_intersect($changedResources, $stockResources);
        // A purchase receipt must have its matching movement in this atomic
        // batch even when the client sends only the purchase. Logistics phase
        // changes also release reservations without a direct stock operation.
        if ($hasStockChanges || in_array('support-requests', $changedResources, true)) {
            StockRules::afterTransaction($a,$changes,$tx);
        }
        if ($hasStockChanges) {
            StockRules::checkBalances();
            StockRules::syncAssets($a,$changes,$tx);
        }

        $financeResources = ['invoices', 'financial-documents', 'cash-accounts', 'payments', 'journal-entries', 'payroll-hours', 'payroll-runs'];
        if (array_intersect($changedResources, $financeResources)) {
            FinanceRules::afterTransaction($a,$changes,$tx);
        }
        foreach ($changes as $change) if ($change['resource']==='employees'&&$change['after']) {
            $d=$change['after']; $u=User::where('employee_id',(string)$d['id'])->first();
            if ($u) { $u->department=$d['department']; if (!$u->root()&&(($d['active']??true)===false||($d['employmentStatus']??'')==='resigned')) $u->active=false; $u->save(); }
        }

    }
    public static function derived(User $a,string $resource,string $id,array $d,array &$changes,string $tx): void {
        $class=Records::model($resource);$row=$class::find($id);$before=$row?->document();unset($d['_version']);
        if ($resource==='employees') self::employee($a,$before,$d,true);
        $row??=new $class(['id'=>$id,'version'=>0]);$row->fill(['data'=>$d,'version'=>$row->version+1,'department'=>$d['department']??null,'status'=>$d['status']??null])->save();
        foreach (config('mosh.spec.relations.'.$resource,[]) as $column=>$target) if ($column==='employee_id'&&!empty($d['employeeId'])) {$row->employee_id=$d['employeeId'];$row->save();}
        $found=false;foreach ($changes as &$c) if ($c['resource']===$resource&&(string)$c['id']===$id) {$c['after']=$row->document();$found=true;break;}unset($c);
        if (!$found) $changes[]=['resource'=>$resource,'id'=>$id,'before'=>$before,'after'=>$row->document()];
        Audit::record($a,Records::spec($resource)['module'],$resource,$id,'workflow',$before,$row->document(),$tx);
    }
    private static function applyHr(User $a,array $r,array &$changes,string $tx): void {
        $p=Employee::find($r['employeeId']);if (!$p) return;$d=$p->document();$id=$r['id'];$type=$r['type'];
        if (in_array($type,['annual_leave','sick_leave'],true)) $d['leaveRecords'][]=['id'=>$id,'type'=>$type==='annual_leave'?'سنوية':'مرضية','startDate'=>$r['data']['startDate'],'endDate'=>$r['data']['endDate'],'status'=>'approved','reason'=>$r['reason'],'createdAt'=>$r['createdAt']];
        if ($type==='hourly_leave') {
            $hours=(strtotime($r['data']['toTime'])-strtotime($r['data']['fromTime']))/3600;
            $d['leaveRecords'][]=['id'=>$id,'type'=>'ساعية','startDate'=>$r['data']['date'],'endDate'=>$r['data']['date'],'fromTime'=>$r['data']['fromTime'],'toTime'=>$r['data']['toTime'],'hours'=>$hours,'days'=>0,'status'=>'approved','reason'=>$r['reason'],'createdAt'=>now()->toIso8601String()];
        }
        if ($type==='attendance_correction') $d['attendanceRecords'][]=['id'=>$id,'date'=>$r['data']['date'],'inTime'=>$r['data']['fromTime'],'outTime'=>$r['data']['toTime'],'type'=>'correction','note'=>$r['reason'],'status'=>'approved'];
        if (in_array($type,['advance','reward','deduction'],true)) $d['payrollRecords'][]=['id'=>$id,'type'=>$type,'amount'=>$r['data']['amount'],'note'=>$r['reason'],'date'=>now()->toDateString(),'status'=>'approved'];
        if ($type==='promotion'&&!empty($r['data']['jobTitle'])) $d['jobTitle']=$r['data']['jobTitle'];
        if ($type==='transfer'&&!empty($r['data']['target'])) $d['department']=$r['data']['target'];
        if ($d!==$p->document()) self::derived($a,'employees',$p->id,$d,$changes,$tx);
        if (in_array($type,['star_month','appreciation'],true)) self::derived($a,'recognition',$id,['id'=>$id,'type'=>$type,'employeeId'=>$p->id,'employeeName'=>$d['name'],'reason'=>$r['reason'],'month'=>($r['data']['target']??'')?:now()->format('Y-m'),'createdAt'=>now()->toIso8601String()],$changes,$tx);
        if ($type==='termination') {$oid='OFF-'.Str::ulid();self::derived($a,'offboarding',$oid,['id'=>$oid,'employeeId'=>$p->id,'employeeName'=>$d['name'],'reason'=>($r['data']['target']??'')?:$r['reason'],'lastWorkDay'=>$r['data']['endDate']??'','hr'=>false,'logistics'=>false,'accounting'=>false,'system'=>false,'status'=>'open','requestId'=>$id],$changes,$tx);}
    }
    public static function departments(): array {
        return ['تكنولوجيا المعلومات','الإدارة','الموارد البشرية','المبيعات','التسويق','السوشال ميديا','التصميم','التصوير','المونتاج','الترويج','اللوجستيات','المشتريات','المحاسبة','المستودعات العامة','المهمات'];
    }
    public static function activeEmployee(string $id): bool {
        $p=Employee::find($id);return $p&&($p->data['active']??true)&&($p->data['employmentStatus']??'active')==='active';
    }
    public static function clauses(mixed $rows): void {
        self::need(is_array($rows)&&array_is_list($rows),'البنود قائمة مرتبة.');
        Validator::make(['clauses'=>$rows],['clauses'=>'array|max:500','clauses.*'=>'array','clauses.*.id'=>'required|string|max:190|distinct','clauses.*.title'=>'required|string|max:500','clauses.*.body'=>'required|string|max:20000'])->validate();
    }
    private static function legacyApproval(User $a,?array $old,array &$d): void {
        $flows=['termination'=>['مسؤول الموارد البشرية','المدير التنفيذي / الإدارة العليا'],'leave_override'=>['المدير المباشر','مسؤول الموارد البشرية'],'reward'=>['مسؤول الموارد البشرية','المدير التنفيذي / الإدارة العليا'],'disciplinary'=>['مسؤول الموارد البشرية','المدير التنفيذي / الإدارة العليا']];
        self::need(isset($flows[$d['type']]),'نوع موافقة غير صالح.');
        $p=Employee::find($d['employeeId']);self::need((bool)$p,'الموظف غير موجود.');
        Validator::make($d,['sourceId'=>'required|string|max:190'])->validate();
        if ($d['type']==='termination') Validator::make($d['payload']??[],['lastWorkDay'=>'required|date_format:Y-m-d','reason'=>'required|string|max:20000'])->validate();
        else {
            $field=['leave_override'=>'leaveRecords','reward'=>'rewardRecords','disciplinary'=>'disciplinaryRecords'][$d['type']];
            $source=collect($p->data[$field]??[])->first(fn($x)=>(string)$x['id']===(string)$d['sourceId']);
            self::need((bool)$source,'مصدر الاعتماد غير موجود في ملف الموظف.');
            foreach (['level','amount','currency'] as $key) if (isset($source[$key])) {
                self::need(!isset($d['payload'][$key])||$d['payload'][$key]==$source[$key],'تفاصيل الاعتماد لا تطابق المصدر.');$d['payload'][$key]=$source[$key];
            }
            if (!empty($source['approvalId'])) self::need((string)$source['approvalId']===(string)$d['id'],'المصدر مرتبط باعتماد آخر.');
        }
        if (!$old) {
            self::need(!\App\Models\HrApproval::where('data->employeeId',$d['employeeId'])->where('data->type',$d['type'])->where('data->sourceId',$d['sourceId'])->exists(),'المصدر مرتبط باعتماد سابق.');
            $d['levels']=$flows[$d['type']];if($d['type']==='disciplinary'&&!in_array($d['payload']['level']??'',['warning3','termination_notice'],true))$d['levels']=['مسؤول الموارد البشرية'];
            $d['status']='pending';$d['currentLevel']=0;
            \App\Services\R34\Hierarchy::prepareHr($a,null,$d,'levels');
        } else {
            \App\Services\R34\Hierarchy::prepareHr($a,$old,$d,'levels');
            \App\Services\R34\Hierarchy::guardHrDecision($a,$old,$d);
            self::unchanged($old,$d,['type','employeeId','sourceId','payload','levels']);
            Validator::make($d,['currentLevel'=>'required|integer|min:0','status'=>'required|in:pending,approved,rejected'])->validate();
            if ($d['currentLevel']!==$old['currentLevel']||$d['status']!==$old['status']) {
                self::need($old['status']==='pending','الاعتماد مغلق.');
                $level=$old['currentLevel'];$last=$level===count($old['levels'])-1;
                if ($d['status']==='pending') self::need(!$last&&$d['currentLevel']===$level+1,'مرحلة غير صالحة.');
                else {self::need($d['currentLevel']===$level,'القرار لا يغير رقم المرحلة.');if($d['status']==='approved')self::need($last,'أكمل مراحل الاعتماد.');$d['completedAt']=now()->toIso8601String();}
            }
        }
    }
    private static function workflowEffects(User $a,array &$changes,string $tx): void {
        foreach (array_values($changes) as $change) {
            $d=$change['after'];$old=$change['before'];$resource=$change['resource'];
            if ($resource==='offboarding'&&($d['status']??'')==='closed'&&($old['status']??'')!=='closed') {
                $p=Employee::find($d['employeeId']);self::need((bool)$p,'الموظف غير موجود.');
                $employee=$p->document();$employee['employmentStatus']='resigned';$employee['active']=false;
                $employee['resignationDate']=$d['lastWorkDay']??'';$employee['resignationReason']=$d['reason']??'';
                self::derived($a,'employees',$p->id,$employee,$changes,$tx);
            }
            if ($resource==='announcements') {
                $notice=$d??$old;
                foreach ($notice['recipientIds']??[] as $id) {
                    $p=Employee::find($id);if(!$p)continue;$employee=$p->document();$copies=$employee['hrNoticeArchive']??[];
                    $found=false;
                    foreach ($copies as &$copy) if (($copy['noticeId']??'')===$notice['id']) {
                        $found=true;
                        if (!$d||!empty($d['deletedAt'])||($d['active']??true)===false) {$copy['deletedAt']=now()->toIso8601String();$copy['deletedBy']=$a->name;}
                        if (!empty($notice['readAtBy'][$id])) $copy['readAt']=$notice['readAtBy'][$id];
                    }unset($copy);
                    if (!$found&&$d&&($d['type']??'')==='alert') $copies[]=['id'=>'NOTICE:'.$notice['id'].':'.$id,'noticeId'=>$notice['id'],'employeeId'=>$id,'employeeName'=>$p->data['name'],'type'=>'alert','title'=>$notice['title'],'message'=>$notice['message'],'originalTargetType'=>$notice['targetType'],'createdAt'=>$notice['createdAt'],'createdBy'=>$notice['createdBy'],'readAt'=>$notice['readAtBy'][$id]??''];
                    if ($copies!==($employee['hrNoticeArchive']??[])) {$employee['hrNoticeArchive']=$copies;self::derived($a,'employees',$p->id,$employee,$changes,$tx);}
                }
            }
            if ($resource==='employees'&&$d) foreach (['leaveRecords','disciplinaryRecords','rewardRecords'] as $field) foreach ($d[$field]??[] as $record) if (!empty($record['approvalId'])) {
                $approval=\App\Models\HrApproval::find($record['approvalId']);
                self::need($approval&&(string)$approval->data['employeeId']===(string)$d['id']&&(string)($approval->data['sourceId']??'')===(string)$record['id'],'مرجع اعتماد الموظف غير صالح.');
            }
        }
        // Defaults are a cross-record invariant, checked after all operations of an atomic save.
        if (collect($changes)->contains(fn($c)=>$c['resource']==='quotation-templates')) {
            $templates=\App\Models\QuotationTemplate::all();$defaults=$templates->filter(fn($t)=>!empty($t->data['isDefault']));
            self::need($defaults->count()<=1,'اختر قالباً افتراضياً واحداً.');
            foreach ($defaults as $template) self::need(($template->data['active']??true)!==false,'القالب الافتراضي يجب أن يكون فعالاً.');
        }
        if (collect($changes)->contains(fn($c)=>$c['resource']==='tasks')) foreach (Task::all() as $task) {
            $d=$task->document();
            if (in_array($d['status']??'',['completed','awaiting_invoice'],true)) self::need(!Task::where('parent_id',(string)$d['id'])->whereNotIn('status',['completed','awaiting_invoice','archived_invoiced','archived_no_invoice','cancelled'])->exists(),'أكمل المهام الفرعية أولاً.');
            if (!empty($d['sharedDistributionChild'])) self::need(!empty($d['sharedRootTaskId']),'مرجع أصل المهمة المشتركة مطلوب.');
            if (!empty($d['sharedRootTaskId'])) {
                $root=Task::find($d['sharedRootTaskId']);self::need($root&&!empty($root->data['sharedDistributionRoot'])&&(string)$root->id!==(string)$d['id'],'أصل المهمة المشتركة غير صالح.');
                $parent=Task::find($d['parentTaskId']??'');
                self::need($parent&&((string)$parent->id===(string)$root->id||(string)($parent->data['sharedRootTaskId']??'')===(string)$root->id),'الأب لا يتبع أصل المهمة المشتركة.');
                self::need(($d['customer']??'')===($root->data['customer']??''),'عميل المهمة المشتركة مختلف.');
            }
            if (($d['status']??'')==='shared_container') self::need(!empty($d['sharedTeamContainer'])&&empty($d['assigneeId']),'الحالة لحاوية فريق غير مسندة فقط.');
        }
    }

}

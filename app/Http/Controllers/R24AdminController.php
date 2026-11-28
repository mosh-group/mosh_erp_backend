<?php
namespace App\Http\Controllers;

use App\Models\{AdminDecision,Employee,Task,WorkPresence,SupportRequest,User,Setting};
use App\Support\{AdminTaskTakeover,Audit,R19Store,R19HrWorkflow,R19HrAccess,RecordAccess,TaskExecutionClock};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Hash,Validator};
use Illuminate\Support\Str;

/** R24 administration: additive APIs; does not replace existing tasks, purchasing
 * transactions, payroll, attendance, or their immutable audit records. */
final class R24AdminController extends Controller
{
    private const ALERT_PREFIX = '_r24_admin_alert_';
    private const DECISION_TITLE_MAX = 200;

    private static function canView(User $u): bool
    { return $u->active && ($u->root() || $u->administration() || $u->allows('admin','view')); }
    private static function canEdit(User $u): bool
    { return $u->active && ($u->root() || $u->administration() || $u->allows('admin','edit')); }
    private static function canIssue(User $u): bool
    { return $u->active && ($u->root() || $u->allows('admin','create')); }
    private static function canApprove(User $u): bool
    { return $u->active && ($u->root() || $u->allows('admin','approve')); }
    private static function canReadReport(User $u): bool
    { return $u->active && ($u->root() || $u->allows('admin','reports') || $u->allows(User::departmentApp($u->department),'reports')); }
    private static function canAllReports(User $u): bool
    { return $u->root() || $u->allows('admin','reports'); }
    private static function requireAdmin(User $u): void { abort_unless(self::canView($u), 403, 'صلاحية الإدارة غير متاحة.'); }
    private static function text(mixed $v, int $max = 4000): string { return mb_substr(trim((string)$v),0,$max); }
    private static function now(): string { return now()->toIso8601String(); }
    private static function decisionData(AdminDecision $row): array {
        return array_merge($row->data??[], ['id'=>(string)$row->id, '_version'=>(int)$row->version,
            'createdAt'=>$row->created_at?->toIso8601String(), 'updatedAt'=>$row->updated_at?->toIso8601String()]);
    }
    private static function actionAllowed(User $u, array $data, bool $all = false): bool {
        return $u->active && ($u->root() || (bool)data_get($u->data,'r24EditAllDecisions',false)
            || (self::canEdit($u) && (!$all && (string)($data['issuerId']??'')===(string)$u->id)));
    }
    private static function year(string $date): int { return (int)substr($date,0,4); }
    private static function numberCandidates(array $d, string $id): array {
        $out=[$id];foreach (['number','decisionNo','decisionNumber','decision_number','decision_no','orderNo','order_number','serial','serialNo','number','no','code','reference','referenceNo','documentNo','رقم القرار','رقم'] as $k)
            if(isset($d[$k]) && is_scalar($d[$k]))$out[]=(string)$d[$k];
        return array_values(array_unique(array_filter(array_map('trim',$out))));
    }
    private static function numericEnglish(string $value): string {
        return strtr($value,['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9','۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']);
    }
    /** Executed exclusively under R19Store::commit business lock. Scan live legacy
     * decisions, never reset numbering to local/seed/backup data. */
    private static function nextNumber(int $year): string {
        $highest=0;$used=[];
        foreach (AdminDecision::query()->cursor() as $item) {
            $d=$item->data??[];
            $rowYear=self::year((string)($d['date']??$d['issuedAt']??$d['createdDate']??$item->created_at?->format('Y-m-d')??''));
            foreach (self::numberCandidates($d,(string)$item->id) as $candidate) {
                $n=self::numericEnglish($candidate);$used[$n]=true;
                if(preg_match('/^ADM[-\/ ]?(\d{4})[-\/ ]?(\d+)$/iD',$n,$m) && (int)$m[1]===$year) $highest=max($highest,(int)$m[2]);
                // Old records may use "12" or "12/2026" instead of an ADM prefix.
                elseif(preg_match('/^(\d{1,8})$/D',$n,$m) && $rowYear===$year) $highest=max($highest,(int)$m[1]);
                elseif(preg_match('/^(\d{1,8})[-\/](\d{4})$/D',$n,$m) && (int)$m[2]===$year) $highest=max($highest,(int)$m[1]);
                elseif(preg_match('/^(\d{4})[-\/](\d{1,8})$/D',$n,$m) && (int)$m[1]===$year) $highest=max($highest,(int)$m[2]);
            }
        }
        do {$highest++;$serial='ADM-'.$year.'-'.str_pad((string)$highest,3,'0',STR_PAD_LEFT);} while(isset($used[$serial]));
        return $serial;
    }
    private static function requestedRecipients(array $input): array {
        $scope=(string)($input['recipientScope']??'employees');
        if($scope==='all') {
            $list=Employee::all()->filter(fn($p)=>($p->data['active']??true) && ($p->data['employmentStatus']??'active')==='active')->pluck('id')->map(fn($id)=>(string)$id)->all();
        }elseif($scope==='department') {
            $department=(string)($input['recipientDepartment']??'');abort_unless($department!=='',422,'اختر القسم المعني.');
            $list=Employee::all()->filter(fn($p)=>($p->data['department']??'')===$department && ($p->data['active']??true))->pluck('id')->map(fn($id)=>(string)$id)->all();
        }else $list=array_values(array_unique(array_filter(array_map('strval',$input['recipientIds']??[]))));
        abort_if(count($list)>1000,422,'عدد المستلمين كبير.');
        if($list) {
            $valid=Employee::whereIn('id',$list)->count();
            abort_unless($valid===count($list),422,'أحد الموظفين غير موجود.');
        }
        return $list;
    }

    public function overview(Request $request) {
        $u=$request->user();self::requireAdmin($u);
        $people=Employee::orderBy('id')->get();$tasks=Task::all()->map(fn($r)=>$r->document());
        $running=[];
        foreach($tasks as $d) {
            $id=(string)($d['workTiming']['activeWorkerId']??$d['assigneeId']??'');if(!$id || ($d['employeeState']??'')!=='working' || ($d['status']??'')!=='in_progress')continue;
            $start=TaskExecutionClock::epoch($d['workTiming']['activeStartedAt']??null)??0;
            if(!$start) continue;
            if(!isset($running[$id]) || $start>($running[$id]['startedAt']??0))$running[$id]=[
                'id'=>(string)$d['id'],'title'=>(string)($d['title']??'مهمة'),'startedAt'=>$start];
        }
        $presences=WorkPresence::all()->mapWithKeys(fn($r)=>[(string)($r->data['employeeId']??$r->employee_id??'')=>
            array_merge($r->data??[],['_rowUpdatedMs'=>$r->updated_at?->getTimestampMs()??0])])->all();
        $employees=[];$count=['working'=>0,'break'=>0,'no_work'=>0,'untracked'=>0,'off'=>0,'unknown'=>0];
        $todayStart=now()->timezone(config('app.timezone','Asia/Damascus'))->startOfDay()->getTimestampMs();
        foreach($people as $p){$d=$p->document();$eid=(string)$p->id;
            if(($d['active']??true)===false || ($d['employmentStatus']??'active')!=='active')continue;
            $presence=$presences[$eid]??[];$mode=(string)($presence['mode']??'off');
            $status= $mode==='break'?'break':($mode==='active'?(!empty($presence['noWork'])?'no_work':(isset($running[$eid])?'working':'untracked')):'off');
            // WorkPresence is not a fingerprint clock. A persisted active state
            // from yesterday must never be advertised as live attendance today.
            $lastUpdate=TaskExecutionClock::epoch($presence['updatedAt']??null)??(int)($presence['_rowUpdatedMs']??0);
            if($mode!=='off' && ($lastUpdate===0 || $lastUpdate<$todayStart))$status='unknown';
            $task=$status==='working'?($running[$eid]??null):null;
            $changedAt=TaskExecutionClock::epoch($task['startedAt']??$presence['noWorkAt']??$presence['breakAt']??$presence['updatedAt']??null)
                ??(int)($presence['_rowUpdatedMs']??0);
            $employees[]=['id'=>$eid,'name'=>(string)($d['name']??''),'department'=>(string)($d['department']??''),
                'jobTitle'=>(string)($d['jobTitle']??''),'status'=>$status,'taskId'=>(string)($task['id']??''),
                'taskTitle'=>(string)($task['title']??''),'sinceAt'=>$changedAt,'mode'=>$mode,
                'activitySource'=>'work-presence','attendanceConfirmed'=>false];
            $count[$status]++;
        }
        $disciplinary=R19Store::prefixed(R19HrWorkflow::DISCIPLINARY_PREFIX);
        $pending=count(array_filter($disciplinary,fn($r)=>($r['status']??'')==='pending_admin'));
        $alerts=R19Store::prefixed(self::ALERT_PREFIX);
        $newReplies=count(array_filter($alerts,fn($r)=>!empty($r['reply'])&&empty($r['replySeenAt'])));
        return $this->ok(['employees'=>$employees,'totals'=>$count,'pendingApprovals'=>$pending,'newReplies'=>$newReplies,
            'serverTime'=>self::now(),'sourceNote'=>'حالات النشاط مأخوذة من لوحة عملي، وليست دليلاً منفرداً على بصمة الحضور.',
            'departments'=>array_values(array_unique(array_column($employees,'department')))]);
    }
    public function takeoverTask(Request $request)
    {
        return $this->ok(AdminTaskTakeover::execute($request->user(), $request->all()))
            ->header('Cache-Control', 'private, no-store');
    }

    public function task(Request $r,string $id) {
        self::requireAdmin($r->user());$row=Task::findOrFail($id);$d=$row->document();
        return $this->ok(['id'=>(string)$row->id,'title'=>$d['title']??'','department'=>$d['department']??'',
            'assignee'=>$d['assignee']??'','status'=>$d['status']??'','employeeState'=>$d['employeeState']??'',
            'notes'=>$d['notes']??'','description'=>$d['description']??'','workChat'=>$d['workChat']??[],
            'attachments'=>$d['attachments']??[],'workTiming'=>$d['workTiming']??[], 'history'=>$d['history']??[]]);
    }
    public function alerts(Request $r) {
        self::requireAdmin($r->user());$rows=R19Store::prefixed(self::ALERT_PREFIX);
        usort($rows,fn($a,$b)=>strcmp($b['createdAt']??'',$a['createdAt']??''));
        return $this->ok(['items'=>$rows]);
    }
    public function alertSend(Request $r) {
        $u=$r->user();abort_unless($u && self::canIssue($u),403,'إرسال التنبيه يحتاج صلاحية إصدار إجراء إداري.');
        $v=$r->validate(['employeeId'=>'required|string|max:190','target'=>'required|in:employee,manager,both',
            'message'=>'required|string|min:3|max:3000','requestId'=>'required|string|min:8|max:128']);
        $res=R19Store::commit($u,'r24.alert.send',$v,function($tx)use($u,$v){
            $e=Employee::findOrFail($v['employeeId']);$d=$e->document();$managerId=(string)($d['directManagerId']??'');
            if(!$managerId && in_array($v['target'],['manager','both'],true)) {
                $name=(string)($d['directManager']??'');if($name)$managerId=(string)(Employee::where('data->name',$name)->value('id')??'');
            }
            abort_if(in_array($v['target'],['manager','both'],true)&&!$managerId,422,'لم يتم تحديد ملف مدير القسم.');
            $recipients=match($v['target']){'employee'=>[(string)$e->id],'manager'=>[$managerId],default=>[(string)$e->id,$managerId]};
            $id='AL24-'.Str::ulid();$doc=['id'=>$id,'employeeId'=>(string)$e->id,'employeeName'=>(string)($d['name']??''),
                'department'=>(string)($d['department']??''),'recipientIds'=>array_values(array_unique($recipients)),
                'message'=>self::text($v['message'],3000),'sentBy'=>(string)$u->id,'sentByName'=>(string)$u->name,
                'createdAt'=>self::now(),'reply'=>'','replyAt'=>null,'replySeenAt'=>null,'readBy'=>[]];
            $saved=R19Store::put(self::ALERT_PREFIX.$id,$doc,0);
            Audit::record($u,'admin','r24-alert',$id,'create',null,$saved,$tx);
            return ['id'=>$id];
        });
        return $this->ok(R19Store::get(self::ALERT_PREFIX.$res['id']),201);
    }
    /** A recipient may read their own alert; managers may inspect their sent alerts.
     * No employee-directory or administrative list is exposed here. */
    public function alertRecord(Request $r,string $id) {
        $u=$r->user();abort_unless($u && $u->active,403);
        $record=R19Store::get(self::ALERT_PREFIX.$id);abort_unless($record,404);
        $receivers=array_map('strval',$record['recipientIds']??[]);
        abort_unless((!empty($u->employee_id) && in_array((string)$u->employee_id,$receivers,true))
            || (string)($record['sentBy']??'')===(string)$u->id
            || self::canView($u),403,'هذا التنبيه غير متاح لحسابك.');
        return $this->ok($record);
    }
    /** Full decision can be opened from a delivered notification. */
    public function decisionRecipient(Request $r,string $id) {
        $u=$r->user();abort_unless($u && $u->active,403);
        $row=AdminDecision::findOrFail($id);$d=self::decisionData($row);
        $receivers=array_map('strval',$d['recipientIds']??[]);
        abort_unless(self::canView($u) || (!empty($d['notify']) && (
            (!empty($u->employee_id) && in_array((string)$u->employee_id,$receivers,true)))),403,
            'هذا القرار لم يتم إبلاغك به.');
        // No database mutation, even on cancelled or previously numbered orders.
        return $this->ok(['id'=>$d['id'],'number'=>$d['number']??$d['decisionNo']??$d['orderNo']??$d['id'],
          'title'=>$d['title']??'','body'=>$d['body']??'',
          'issuedAt'=>$d['issuedAt']??$d['date']??'',
          'effectiveDate'=>$d['effectiveDate']??$d['date']??'',
          'status'=>$d['status']??'','revision'=>$d['revision']??1,
          'cancelReason'=>$d['cancelReason']??'',
          'history'=>$d['history']??[]]);
    }
    public function alertReply(Request $r,string $id){
        $u=$r->user();$v=$r->validate(['message'=>'required|string|min:2|max:3000','requestId'=>'required|string|min:8|max:128']);
        $res=R19Store::commit($u,'r24.alert.reply.'.$id,$v,function($tx)use($u,$v,$id){
            $old=R19Store::get(self::ALERT_PREFIX.$id);abort_unless($old,404);
            $recipients=array_map('strval',$old['recipientIds']??[]);
            abort_unless($u && $u->active && ((!empty($u->employee_id) && in_array((string)$u->employee_id,$recipients,true))),403,'الرد مسموح للمستلم المحدد فقط.');
            abort_if(!empty($old['reply']),409,'تم الرد على التنبيه مسبقاً.');
            $next=$old;$next['reply']=self::text($v['message'],3000);$next['replyAt']=self::now();$next['replyBy']=(string)$u->id;
            $saved=R19Store::put(self::ALERT_PREFIX.$id,$next,$old['_version']);Audit::record($u,'admin','r24-alert',$id,'reply',$old,$saved,$tx);
            return ['id'=>$id];
        });return $this->ok(R19Store::get(self::ALERT_PREFIX.$res['id']));
    }
    public function alertSeen(Request $r,string $id){
        $u=$r->user();self::requireAdmin($u);$v=$r->validate(['requestId'=>'required|string|min:8|max:128']);
        $res=R19Store::commit($u,'r24.alert.seen.'.$id,$v,function($tx)use($u,$id){
            $old=R19Store::get(self::ALERT_PREFIX.$id);abort_unless($old,404);
            if(empty($old['replySeenAt'])){$new=$old;$new['replySeenAt']=self::now();$saved=R19Store::put(self::ALERT_PREFIX.$id,$new,$old['_version']);Audit::record($u,'admin','r24-alert',$id,'seen',$old,$saved,$tx);}
            return ['id'=>$id];
        });return $this->ok(R19Store::get(self::ALERT_PREFIX.$res['id']));
    }
    public function approvals(Request $r) {
        $u=$r->user();self::requireAdmin($u);$status=$r->validate(['status'=>'sometimes|in:mine,all'])['status']??'mine';
        $rows=[];
        foreach(R19Store::prefixed(R19HrWorkflow::DISCIPLINARY_PREFIX) as $d){
            $pending=($d['status']??'')==='pending_admin';if($status==='mine'&&!($pending&&self::canApprove($u)))continue;
            $rows[]=['id'=>$d['id'],'source'=>'disciplinary','title'=>($d['type']??'')==='deduction'?'طلب خصم':'طلب عقوبة',
                'requester'=>$d['submittedByName']??'','department'=>$d['department']??'','stage'=>'موافقة الإدارة',
                'status'=>$d['status']??'','canDecide'=>$pending&&self::canApprove($u),
                'reason'=>$d['reason']??'','details'=>$d['details']??'','attachments'=>$d['attachments']??[],
                'history'=>$d['history']??[],'amount'=>$d['amount']??null,'currency'=>$d['currency']??'',
                '_version'=>$d['_version']??0];
        }
        // R24-managed requests: only the explicitly selected approver may act.
        foreach(SupportRequest::all() as $request) {
            $d=$request->data??[];$m=$d['operation18']??[];$p=$m['r24']??[];
            if(($d['type']??'')!=='purchase'||empty($p['policyId']))continue;
            $pending=in_array($p['stage']??'',['quotation_pending','purchase_pending'],true);
            $mine=$pending && \App\Support\R24PurchaseRules::expected($p)===(string)$u->id;
            if($status==='mine'&&!$mine)continue;
            $rows[]=['id'=>(string)$request->id,'source'=>'purchase-managed','title'=>$d['title']??'',
              'requester'=>$d['createdBy']??'','department'=>$d['sourceDepartment']??'',
              'stage'=>($p['stage']??'')==='quotation_pending'?'اعتماد طلب عروض الأسعار':'اعتماد الشراء النهائي',
              'status'=>$p['stage']??'', 'canDecide'=>$mine,
              'details'=>$d['details']??'','attachments'=>$d['attachments']??[],
              'history'=>$p['history']??[], 'quotes'=>$m['quotes']??[],
              'amount'=>$m['approval']['total']['total']??null,'_version'=>$request->version];
        }
        // Existing purchasing approval is owned by Ops18. Surface it here for review,
        // but do not approve it with a shadow state that skips supplier/order validation.
        foreach(SupportRequest::all() as $request){$d=$request->document();if(($d['type']??'')!=='purchase'||!empty($d['operation18']['r24']))continue;
            $phase=(string)($d['operation18']['phase']??$d['status']??'new');$waiting=in_array($phase,['review','deferred'],true);
            if($status==='mine'&&!($waiting&&self::canApprove($u)))continue;
            $rows[]=['id'=>(string)$request->id,'source'=>'purchase-legacy','title'=>$d['title']??$d['item']??'',
                'requester'=>$d['createdBy']??'','department'=>$d['sourceDepartment']??'',
                'stage'=>'مراجعة المشتريات','status'=>$phase,'canDecide'=>false,'openInPurchasing'=>true,
                'details'=>$d['details']??'','attachments'=>$d['attachments']??[],
                'history'=>$d['history']??[],'amount'=>null,'_version'=>$d['_version']??0];
        }
        return $this->ok(['items'=>$rows]);
    }
    public function approvalDecision(Request $r,string $id) {
        $u=$r->user();abort_unless(self::canApprove($u),403);
        $v=$r->validate(['decision'=>'required|in:approve,reject','note'=>'nullable|string|max:4000','version'=>'required|integer|min:1','requestId'=>'required|string|min:8|max:128']);
        return $this->ok(R19HrWorkflow::decideDisciplinary($u,$id,$v));
    }
    public function purchases(Request $r) {
        self::requireAdmin($r->user());$rows=[];
        foreach(SupportRequest::orderBy('created_at','desc')->get() as $request){$d=$request->document();if(($d['type']??'')!=='purchase')continue;
            $op=$d['operation18']??[];
            $rows[]=['id'=>$d['id'],'number'=>$d['requestNo']??'','title'=>$d['title']??$d['item']??'',
                'department'=>$d['sourceDepartment']??'','responsible'=>$d['assignedTo']??'',
                'stage'=>$op['r24']['stage']??$op['phase']??$d['status']??'','supplier'=>$d['supplier']??'',
                'quotes'=>count($op['quotes']??[]),'total'=>$op['approvedAmount']??null,
                'received'=>$op['receivedQty']??null,'ordered'=>$d['quantity']??null,
                'paid'=>$op['paidAmount']??null, 'attachments'=>$d['attachments']??[],
                'history'=>$d['history']??[]];
        }return $this->ok(['items'=>$rows,'readOnly'=>true,'source'=>'support-requests']);
    }

    public function noticeCreate(Request $r) {
        $u=$r->user();abort_unless($u->active && ($u->root()||$u->allows('admin','create')||$u->allows('hr','create')),403);
        $v=$r->validate(['type'=>'required|in:announcement,alert','title'=>'required|string|max:250',
            'message'=>'required|string|max:10000','targetType'=>'required|in:all,department,employee,employees',
            'targetValue'=>'nullable|string|max:20000','requestId'=>'required|string|min:8|max:128']);
        $res=R19Store::commit($u,'r24.notice.create',$v,function($tx)use($u,$v){
            $payload=['type'=>$v['type'],'title'=>trim($v['title']),'message'=>trim($v['message']),
                'targetType'=>$v['targetType'],'targetValue'=>$v['targetValue']??'',
                'sourceDepartment'=>$u->department,'active'=>true];
            $changed=\App\Support\Records::change($u,['resource'=>'announcements','action'=>'create','data'=>$payload],$tx);
            return ['id'=>$changed['id']];
        });return $this->ok(['id'=>$res['id']],201);
    }

    public function decisions(Request $r){self::requireAdmin($r->user());
        $rows=AdminDecision::orderBy('created_at','desc')->get()->map(function($row){$d=self::decisionData($row);
            // Existing decisions may have no new-style number. Retain the original
            // fields untouched; never manufacture or rewrite an old number.
            return $d;
        })->all();return $this->ok(['items'=>$rows]);
    }
    public function decisionCreate(Request $r){
        $u=$r->user();abort_unless(self::canIssue($u),403);
        $v=$r->validate(['title'=>'required|string|max:200','body'=>'required|string|max:30000',
            'effectiveDate'=>'required|date_format:Y-m-d','notify'=>'sometimes|boolean',
            'recipientScope'=>'sometimes|in:all,department,employees','recipientDepartment'=>'nullable|string|max:120',
            'recipientIds'=>'sometimes|array|max:1000','recipientIds.*'=>'string|max:190',
            'requestId'=>'required|string|min:8|max:128']);
        $recipients=($v['notify']??false)?self::requestedRecipients($v):[];
        $result=R19Store::commit($u,'r24.decision.create',$v,function($tx)use($u,$v,$recipients){
            $at=self::now();$date=now()->toDateString();$no=self::nextNumber((int)substr($date,0,4));
            $id='ADM24-'.Str::ulid();
            $d=['id'=>$id,'number'=>$no,'title'=>self::text($v['title'],200),'body'=>trim($v['body']),
                'date'=>$date,'issuedAt'=>$at,'effectiveDate'=>$v['effectiveDate'],'issuerId'=>(string)$u->id,
                'owner'=>(string)$u->name,'status'=>'issued','notify'=>(bool)($v['notify']??false),
                'recipientIds'=>$recipients,'revision'=>1,'signature'=>null,
                'versions'=>[['revision'=>1,'body'=>trim($v['body']),'title'=>self::text($v['title'],200),'at'=>$at,'by'=>(string)$u->id,'signed'=>false]],
                'history'=>[['action'=>'issued','at'=>$at,'by'=>(string)$u->id]]];
            $row=AdminDecision::create(['id'=>$id,'data'=>$d,'version'=>1,'department'=>'الإدارة','status'=>'issued']);
            Audit::record($u,'admin','admin-decisions',$id,'create',null,$row->document(),$tx);
            return ['id'=>$id];
        });return $this->ok(self::decisionData(AdminDecision::findOrFail($result['id'])),201);
    }
    public function decisionUpdate(Request $r,string $id){
        $u=$r->user();$v=$r->validate(['title'=>'required|string|max:200','body'=>'required|string|max:30000',
            'effectiveDate'=>'required|date_format:Y-m-d','version'=>'required|integer|min:1','requestId'=>'required|string|min:8|max:128']);
        $res=R19Store::commit($u,'r24.decision.update.'.$id,$v,function($tx)use($u,$v,$id){
            $row=AdminDecision::lockForUpdate()->findOrFail($id);$old=self::decisionData($row);
            abort_unless(self::actionAllowed($u,$old),403);
            abort_unless((int)$v['version']===(int)$row->version,409,'تغير القرار؛ حدّث الصفحة.');
            abort_if(($old['status']??'')==='cancelled',409,'القرار الملغى لا يُعدل.');
            $d=$row->data??[];$revision=(int)($d['revision']??1)+1;
            $changed=trim($v['body'])!==(string)($d['body']??'') || trim($v['title'])!==(string)($d['title']??'')
                || (string)$v['effectiveDate']!==(string)($d['effectiveDate']??$d['date']??'');
            abort_unless($changed,422,'لم يتغير محتوى القرار، ولا حاجة لإصدار نسخة جديدة.');
            $at=self::now();$next=['title'=>trim($v['title']),'body'=>trim($v['body']),'effectiveDate'=>$v['effectiveDate']];
            if($changed && !empty($d['signature'])) {
                // Preserve the signed original until the altered text is re-signed.
                $d['pendingRevision']=$next+['revision'=>$revision,'by'=>(string)$u->id,'at'=>$at];
                $d['history'][]=['action'=>'draft_revision','at'=>$at,'by'=>(string)$u->id];
            } else {
                $d=array_merge($d,$next);$d['revision']=$revision;$d['signature']=null;
                $d['versions'][]=['revision'=>$revision,'title'=>$next['title'],'body'=>$next['body'],'at'=>$at,'by'=>(string)$u->id,'signed'=>false];
                $d['history'][]=['action'=>'revised','at'=>$at,'by'=>(string)$u->id];
                if(!empty($d['notify']))$d['noticeRevision']=$revision; $d['noticeChangedAt']=$at;
            }
            $row->fill(['data'=>$d,'version'=>$row->version+1]);$row->save();Audit::record($u,'admin','admin-decisions',$id,'edit',$old,$row->document(),$tx);
            return ['id'=>$id];
        });return $this->ok(self::decisionData(AdminDecision::findOrFail($res['id'])));
    }
    public function decisionSign(Request $r,string $id){
        $u=$r->user();$v=$r->validate(['password'=>'required|string|max:300','version'=>'required|integer|min:1','requestId'=>'required|string|min:8|max:128']);
        abort_unless(Hash::check($v['password'],(string)$u->password),403,'كلمة السر غير صحيحة.');
        $res=R19Store::commit($u,'r24.decision.sign.'.$id,['version'=>$v['version'],'requestId'=>$v['requestId']],function($tx)use($u,$v,$id){
            $row=AdminDecision::lockForUpdate()->findOrFail($id);$old=self::decisionData($row);
            abort_unless(self::actionAllowed($u,$old),403);abort_unless((int)$v['version']===$row->version,409);
            abort_if(($old['status']??'')==='cancelled',409);
            $d=$row->data??[];
            $profile=R19Store::get('_r24_signature_'.(string)$u->id);
            abort_unless(!empty($profile['drawnPng']),422,'أضف توقيعك من إعدادات حسابك أولاً.');
            if(!empty($d['pendingRevision'])){
                $pending=$d['pendingRevision'];unset($d['pendingRevision']);
                $d['title']=$pending['title'];$d['body']=$pending['body'];$d['effectiveDate']=$pending['effectiveDate'];$d['revision']=$pending['revision'];
                $d['versions'][]=['revision'=>$d['revision'],'title'=>$d['title'],'body'=>$d['body'],'at'=>self::now(),'by'=>(string)$u->id,'signed'=>true];
                if(!empty($d['notify'])){$d['noticeRevision']=$d['revision']; $d['noticeChangedAt']=self::now();}
            }else{
                $i=array_key_last($d['versions']??[]);if($i!==null)$d['versions'][$i]['signed']=true;
            }
            $activeSeal=\App\Http\Controllers\R24StationeryController::current();
            // Snapshot the approved seal for this revision. Subsequent seal changes
            // must never alter the official document issued today.
            $d['sealSnapshot']=$activeSeal?['id'=>$activeSeal['id'],'fingerprint'=>$activeSeal['fingerprint'],
                'image'=>$activeSeal['image'],'approvedAt'=>$activeSeal['approvedAt']]:null;
            $d['signature']=['by'=>(string)$u->id,'byName'=>(string)$u->name,'at'=>self::now(),
                'revision'=>$d['revision'],'signatureProfileVersion'=>$profile['_version']??1,
                'signatureFingerprint'=>hash('sha256',(string)($profile['drawnPng']??'')),
                'imageDataUrl'=>(string)($profile['drawnPng']??'')];
            $d['history'][]=['action'=>'signed','at'=>self::now(),'by'=>(string)$u->id];
            $row->fill(['data'=>$d,'version'=>$row->version+1]);$row->save();Audit::record($u,'admin','admin-decisions',$id,'sign',$old,$row->document(),$tx);
            return ['id'=>$id];
        });return $this->ok(self::decisionData(AdminDecision::findOrFail($res['id'])));
    }
    public function decisionCancel(Request $r,string $id){
        $u=$r->user();$v=$r->validate(['reason'=>'required|string|min:3|max:4000','version'=>'required|integer|min:1','requestId'=>'required|string|min:8|max:128']);
        $res=R19Store::commit($u,'r24.decision.cancel.'.$id,$v,function($tx)use($u,$v,$id){
            $row=AdminDecision::lockForUpdate()->findOrFail($id);$old=self::decisionData($row);
            abort_unless(self::actionAllowed($u,$old),403);abort_unless((int)$v['version']===$row->version,409);
            abort_if(($old['status']??'')==='cancelled',409);
            $d=$row->data??[];$d['status']='cancelled';$d['cancelledAt']=self::now();$d['cancelReason']=trim($v['reason']);$d['cancelledBy']=(string)$u->id;
            unset($d['pendingRevision']);$d['history'][]=['action'=>'cancelled','at'=>self::now(),'by'=>(string)$u->id,'reason'=>$d['cancelReason']];
            if(!empty($d['notify']))$d['cancelNoticeAt']=self::now();
            $row->fill(['data'=>$d,'version'=>$row->version+1,'status'=>'cancelled']);$row->save();
            Audit::record($u,'admin','admin-decisions',$id,'cancel',$old,$row->document(),$tx);return ['id'=>$id];
        });return $this->ok(self::decisionData(AdminDecision::findOrFail($res['id'])));
    }
    public function signature(Request $r) {
        $u=$r->user();abort_unless(self::canIssue($u),403);
        $profile=R19Store::get('_r24_signature_'.(string)$u->id);
        return $this->ok(['configured'=>!empty($profile['drawnPng']),'_version'=>$profile['_version']??0]);
    }
    public function signatureSave(Request $r) {
        $u=$r->user();abort_unless(self::canIssue($u),403);
        $v=$r->validate(['image'=>'required|string|max:210000','password'=>'required|string|max:300','requestId'=>'required|string|min:8|max:128']);
        abort_unless(Hash::check($v['password'],(string)$u->password),403,'كلمة السر غير صحيحة.');
        abort_unless(str_starts_with($v['image'],'data:image/png;base64,'),422,'التوقيع يجب أن يكون بصيغة PNG.');
        $bytes=base64_decode(substr($v['image'],22),true);
        abort_unless($bytes && strlen($bytes)<155000 && str_starts_with($bytes,"\x89PNG\r\n\x1a\n"),422,'ملف التوقيع غير صالح أو حجمه كبير.');
        R19Store::commit($u,'r24.signature.save',['requestId'=>$v['requestId'],'hash'=>hash('sha256',$v['image'])],function($tx)use($u,$v){
            $key='_r24_signature_'.(string)$u->id;$old=R19Store::get($key)??['_version'=>0];
            $saved=R19Store::put($key,['drawnPng'=>$v['image'],'createdBy'=>(string)$u->id,'updatedAt'=>self::now()],$old['_version']);
            Audit::record($u,'admin','r24-signature',(string)$u->id,'update',['configured'=>!empty($old['drawnPng'])],['configured'=>true,'_version'=>$saved['_version']],$tx);
            return ['id'=>(string)$u->id];
        });return $this->ok(['configured'=>true]);
    }
    public function report(Request $r){
        $u=$r->user();abort_unless(self::canReadReport($u),403);
        $v=$r->validate(['type'=>'required|in:employees,tasks,attendance,purchases,disciplinary',
            'department'=>'nullable|string|max:120','employeeId'=>'nullable|string|max:190',
            'from'=>'nullable|date_format:Y-m-d','to'=>'nullable|date_format:Y-m-d']);
        $dep=(string)($v['department']??'');
        if(!self::canAllReports($u)){
            abort_if($dep!=='' && $dep!==$u->department,403,'التقارير متاحة لقسمك فقط.');
            $dep=$u->department;
        }
        $from=(string)($v['from']??'');$to=(string)($v['to']??'');abort_if($from && $to && $from>$to,422,'الفترة غير صحيحة.');
        $type=$v['type'];$rows=[];$columns=[];$count=0;
        if($type==='employees'){
            $columns=['الموظف','القسم','المسمى الوظيفي','الحالة'];
            foreach(Employee::all() as $p){$d=$p->data??[];if($dep&&($d['department']??'')!==$dep)continue;
                if(!empty($v['employeeId'])&&(string)$p->id!==$v['employeeId'])continue;
                $rows[]=[(string)($d['name']??''),(string)($d['department']??''),(string)($d['jobTitle']??''),(string)($d['employmentStatus']??'active')];}
        }elseif($type==='tasks'){
            $columns=['المهمة','القسم','المنفذ','الحالة','تاريخ الإنشاء','الدقائق المسجّلة'];
            foreach(Task::all() as $p){$d=$p->data??[];$day=substr((string)($d['createdAt']??$p->created_at?->format('Y-m-d')),0,10);
                if($dep&&($d['department']??'')!==$dep || !empty($v['employeeId']) && ($d['assigneeId']??'')!==$v['employeeId'] || $from&&$day<$from || $to&&$day>$to)continue;
                $rows[]=[(string)($d['title']??''),(string)($d['department']??''),(string)($d['assignee']??''),(string)($d['employeeState']??''),$day,
                    (int)floor((int)($d['workTiming']['totalMs']??0)/60000)];}
        }elseif($type==='purchases'){
            $columns=['رقم الطلب','العنوان','القسم','الحالة','تاريخ الإنشاء'];
            foreach(SupportRequest::all() as $p){$d=$p->data??[];if(($d['type']??'')!=='purchase')continue;
                $day=substr((string)($d['createdAt']??''),0,10);if($dep&&($d['sourceDepartment']??'')!==$dep||$from&&$day<$from||$to&&$day>$to)continue;
                $rows[]=[(string)($d['requestNo']??''),(string)($d['title']??$d['item']??''),(string)($d['sourceDepartment']??''),(string)($d['operation18']['phase']??$d['status']??''),$day];}
        }elseif($type==='attendance'){
            $columns=['الموظف','القسم','التاريخ','وقت الدخول','وقت الخروج'];
            foreach(Employee::all() as $p){$d=$p->data??[];if($dep&&($d['department']??'')!==$dep||!empty($v['employeeId'])&&(string)$p->id!==$v['employeeId'])continue;
                foreach($d['attendanceRecords']??[] as $attendance){$day=(string)($attendance['date']??'');if($from&&$day<$from||$to&&$day>$to)continue;
                    $rows[]=[(string)($d['name']??''),(string)($d['department']??''),$day,(string)($attendance['inTime']??''),(string)($attendance['outTime']??'')];}}
        }else{
            $columns=['الموظف','القسم','نوع الإجراء','الحالة','تاريخ الطلب'];
            foreach(R19Store::prefixed(R19HrWorkflow::DISCIPLINARY_PREFIX) as $d){$day=substr((string)($d['createdAt']??''),0,10);
                if($dep&&($d['department']??'')!==$dep||!empty($v['employeeId'])&&($d['employeeId']??'')!==$v['employeeId']||$from&&$day<$from||$to&&$day>$to)continue;
                $rows[]=[(string)($d['employeeName']??''),(string)($d['department']??''),(string)($d['type']??''),(string)($d['status']??''),$day];}
        }
        return $this->ok(['columns'=>$columns,'rows'=>$rows,'count'=>count($rows),'generatedAt'=>self::now(),
            'type'=>$type,'department'=>$dep,'from'=>$from,'to'=>$to,'source'=>'live-database']);
    }
    private static function reportKey(User $u): string{return '_r24_report_presets_'.hash('sha256',(string)$u->id);}
    public function presets(Request $r){abort_unless(self::canReadReport($r->user()),403);return $this->ok(R19Store::get(self::reportKey($r->user()))??['items'=>[],'_version'=>0]);}
    public function presetSave(Request $r){$u=$r->user();abort_unless(self::canReadReport($u),403);
        $v=$r->validate(['id'=>'nullable|string|max:100','name'=>'required|string|min:2|max:150','filters'=>'required|array',
            'filters.type'=>'required|in:employees,tasks,attendance,purchases,disciplinary','filters.department'=>'nullable|string|max:120',
            'filters.employeeId'=>'nullable|string|max:190','filters.from'=>'nullable|date_format:Y-m-d','filters.to'=>'nullable|date_format:Y-m-d',
            'requestId'=>'required|string|min:8|max:128']);
        if(!self::canAllReports($u))abort_if(!empty($v['filters']['department'])&&$v['filters']['department']!==$u->department,403);
        $res=R19Store::commit($u,'r24.report.save',$v,function($tx)use($u,$v){$key=self::reportKey($u);$old=R19Store::get($key)??['_version'=>0,'items'=>[]];
            $items=$old['items']??[];abort_if(count($items)>=50&&!isset($items[$v['id']??'']),422,'الحد الأقصى 50 فلتر.');
            $id=$v['id']??'FILTER-'.Str::ulid();$items[$id]=['id'=>$id,'name'=>self::text($v['name'],150),'filters'=>$v['filters'],'ownerId'=>(string)$u->id];
            R19Store::put($key,['items'=>$items],$old['_version']);return ['id'=>$id];});
        return $this->ok(R19Store::get(self::reportKey($u)));
    }
    public function presetDelete(Request $r,string $id){$u=$r->user();abort_unless(self::canReadReport($u),403);
        $v=$r->validate(['requestId'=>'required|string|min:8|max:128']);
        R19Store::commit($u,'r24.report.delete.'.$id,$v,function($tx)use($u,$id){$key=self::reportKey($u);$old=R19Store::get($key)??['_version'=>0,'items'=>[]];
            $items=$old['items']??[];unset($items[$id]);R19Store::put($key,['items'=>$items],$old['_version']);return ['id'=>$id];});
        return $this->ok(R19Store::get(self::reportKey($u)));
    }
}

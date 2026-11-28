<?php
namespace App\Services\R29;

use App\Models\{Employee,EmploymentContract,User,Setting,StoredFile,FileLink,PayrollHours,FinancialDocument};
use App\Support\{R19Store,R19HrAccess,Records,RecordAccess,BusinessRules,Audit,Money};
use Illuminate\Support\Facades\{DB,Validator};
use Illuminate\Support\Str;

/** Dedicated, versioned workflow in existing tables; no migration or seed needed. */
final class HrWorkflow
{
    public static bool $internal=false;
    public static function today(): string { return now()->timezone(config('app.timezone','Asia/Damascus'))->format('Y-m-d'); }
    public static function hr(User $a,string $action='details'): bool { return $a->active&&($a->root()||(User::departmentApp((string)$a->department)==='hr'&&$a->allows('hr',$action))); }
    public static function canEvaluate(User $a,Employee $p): bool { return $a->active&&($a->root()||((string)$a->employee_id!==(string)$p->id&&$a->manages((string)$p->department)&&$a->allows(User::departmentApp((string)$p->department),'approve'))); }
    public static function state(string $id): array { return R19Store::get('r29:hr:'.$id)??['_version'=>0,'schedules'=>[],'career'=>[],'acknowledged'=>[],'scans'=>[]]; }
    public static function allowed(User $a,Employee $p): bool { return $a->active&&(self::hr($a)||(string)$a->employee_id===(string)$p->id||$a->manages((string)$p->department)); }
    public static function template(string $title): array { return R19Store::get('r29:criteria:'.hash('sha256',trim($title)))??['jobTitle'=>$title,'labels'=>[],'_version'=>0]; }
    public static function index(User $a): array {
        abort_unless($a->active,403);$people=[];$alerts=[];
        foreach(Employee::whereNull('data->archivedAt')->orderBy('created_at')->get()as$p){if(!self::allowed($a,$p))continue;$d=$p->document();$people[]=array_intersect_key($d,array_flip(['id','_version','name','department','jobTitle','employmentStatus','active','hireDate']));
            if(self::hr($a)){ $s=self::state((string)$p->id);foreach(Rules::probation($d,self::today())as$x)if(empty($s['acknowledged'][$x['id']]))$alerts[]=$x; }}
        return ['employees'=>$people,'alerts'=>$alerts,'canCreate'=>self::hr($a,'create'),'canEdit'=>self::hr($a,'edit'),'canApprove'=>self::hr($a,'approve'),'today'=>self::today(),'departments'=>BusinessRules::departments()];
    }
    private static function fieldClockNeedsLocation(array $profile): bool
    {
        $mode = $profile['workMode'] ?? '';
        return is_string($mode) && in_array(trim($mode), ['ميداني', 'مختلط'], true);
    }

    private static function punchLocation(array $input, string $capturedAt): array
    {
        $location = Validator::make($input, [
            'location' => 'required|array:latitude,longitude,accuracy',
            'location.latitude' => 'required|numeric|between:-90,90',
            'location.longitude' => 'required|numeric|between:-180,180',
            'location.accuracy' => 'nullable|numeric|min:0',
        ], [
            'location.required' => 'حدّد موقعك لتسجيل بصمة الدوام الميداني.',
            'location.array' => 'بيانات الموقع غير صالحة.',
            'location.latitude.*' => 'خط العرض غير صالح.',
            'location.longitude.*' => 'خط الطول غير صالح.',
            'location.accuracy.*' => 'دقة الموقع غير صالحة.',
        ])->validate()['location'];

        $punch = [
            'latitude' => (float)$location['latitude'],
            'longitude' => (float)$location['longitude'],
            'capturedAt' => $capturedAt,
        ];
        if (isset($location['accuracy'])) {
            $accuracy = (float)$location['accuracy'];
            abort_unless(is_finite($accuracy), 422, 'دقة الموقع غير صالحة.');
            $punch['accuracy'] = $accuracy;
        }
        return $punch;
    }

    public static function read(User $a,string $id,int $year=0): array {
        $p=Employee::findOrFail($id);abort_unless(self::allowed($a,$p),404);$s=self::state($id);$isHr=self::hr($a);$own=(string)$a->employee_id===$id;$year=$year?:((int)substr(self::today(),0,4));
        $profile=$isHr||$own?$p->document():array_intersect_key($p->document(),array_flip(['id','_version','name','department','jobTitle','employmentStatus','active','evaluationRecords','documents']));
        if(!$isHr&&!$own)unset($profile['documents']);
        $contracts=[];if($isHr)foreach(EmploymentContract::where('employee_id',$id)->get()as$c)$contracts[]=array_merge($c->document(),['signedScan'=>$s['scans'][(string)$c->id]??null]);
        $certificates=[];if($isHr||$own)foreach(R19Store::documents('r29:certificate:')as$c)if(($c['employeeId']??'')===$id)$certificates[]=array_diff_key($c,array_flip(['verifyToken','signatureKey','sealKey','sealSnapshot']));
        $settlements=[];if($isHr||$own)foreach(R19Store::documents('r29:settlement:')as$x)if(($x['employeeId']??'')===$id)$settlements[]=$x;
        $career=$s['career']??[];if(!$isHr&&!$own)$career=[];
        $canClock = $own && R19HrAccess::activeEmployee($p) && in_array(Rules::mode($p->data), ['flexible','daily'], true);
        $attendance = ($isHr || $own) ? ($p->data['attendanceRecords'] ?? []) : [];
        foreach ($attendance as &$record) {
            $locations = $s['attendanceLocations'][(string)($record['id'] ?? '')] ?? [];
            if ($locations) $record['locations'] = $locations;
        }
        unset($record);
        return ['employee'=>$profile,'workflowVersion'=>$s['_version'],'today'=>self::today(),'missing'=>Rules::activationMissing($p->document()),
            'capabilities'=>['edit'=>empty($p->data['archivedAt'])&&self::hr($a,'edit'),'approve'=>empty($p->data['archivedAt'])&&self::hr($a,'approve'),'evaluate'=>empty($p->data['archivedAt'])&&self::canEvaluate($a,$p),'own'=>$own,
                'clock'=>$canClock,'fieldClockLocation'=>$canClock&&self::fieldClockNeedsLocation($p->data),'contracts'=>$isHr],
            'leave'=>self::leaveBalance($p,$year),'schedules'=>$s['schedules']??[],'openShift'=>$s['openShift']??null,
            'attendance'=>$attendance, 'career'=>$career,'contracts'=>$contracts,'certificates'=>$certificates,
            'settlements'=>$settlements,'criteria'=>self::template((string)($p->data['jobTitle']??'')),'evaluations'=>$p->data['evaluationRecords']??[]];
    }
    public static function leaveBalance(Employee $p,int $year): array {
        $d=$p->document();$seen=[];foreach($d['leaveRecords']??[]as$r)$seen[(string)($r['requestId']??$r['sourceRequestId']??$r['id']??'')]=true;
        foreach(\App\Models\HrRequest::where('employee_id',(string)$p->id)->get()as$q){$r=$q->data;if(!in_array($r['type']??'',['annual_leave','hourly_leave'],true)||!in_array($r['status']??'',['pending','requested','awaiting_approval'],true)||isset($seen[(string)$q->id]))continue;
            $v=$r['data']??$r['payload']??[];$v['id']='request:'.$q->id;$v['status']='pending';$v['type']=$r['type'];$v['startDate']=$v['startDate']??$v['date']??'';$v['endDate']=$v['endDate']??$v['startDate'];$d['leaveRecords'][]=$v;}
        return Rules::leave($d,$year,Setting::valueOf('hr')??[]);
    }
    public static function create(User $a,array $in): array {
        $result=R19Store::commit($a,'r29:employee:create',$in,function($tx)use($a,$in){
            abort_unless(self::hr($a,'create'),403);$v=Validator::make($in,['name'=>'required|string|max:200','department'=>'nullable|string|max:200','employeeNo'=>'nullable|string|max:100'])->validate();
            $id=(string)Str::ulid();Records::change($a,['resource'=>'employees','action'=>'create','id'=>$id,'data'=>['name'=>$v['name'],'department'=>$v['department']??'','employmentStatus'=>'draft','active'=>false]+array_intersect_key($v,['employeeNo'=>true])],$tx);return ['employeeId'=>$id];
        });return self::read($a,$result['employeeId']);
    }
    private static function saveEmployee(User $a,Employee $p,array $data,string $tx,string $action): void {
        $old=$p->document();$data['id']=$old['id'];
        self::$internal=true;try{BusinessRules::validate($a,'employees',$old,$data);}finally{self::$internal=false;}
        unset($data['_version']);$data['updatedAt']=now()->toIso8601String();$data['updatedBy']=$a->name;
        $p->update(['data'=>$data,'department'=>$data['department']??null,'version'=>$p->version+1]);
        Audit::record($a,'hr','employees',(string)$p->id,$action,$old,$p->document(),$tx);
        $changes=[['resource'=>'employees','id'=>(string)$p->id,'before'=>$old,'after'=>$p->document()]];BusinessRules::afterTransaction($a,$changes,$tx);$p->refresh();
        if(config('zkteco.enabled'))app(\App\Services\ZKTeco\ZktecoService::class)->syncPrimaryMapping($p);
    }
    private static function file(User $a,string $key,string $resource,string $id): StoredFile {
        $f=StoredFile::where('logical_key',$key)->firstOrFail();
        abort_unless($f->owner_id===$a->id||FileLink::where('file_id',$f->id)->where('resource',$resource)->where('record_id',$id)->exists(),403,'ارفع الملف من حسابك أو استخدم الملف المرتبط بهذا السجل.');
        FileLink::firstOrCreate(['file_id'=>$f->id,'resource'=>$resource,'record_id'=>$id]);return $f;
    }
    public static function mutate(User $a,string $id,array $in): array {
        Validator::make($in,['action'=>'required|string','_version'=>'required|integer|min:1','workflowVersion'=>'required|integer|min:0','viewYear'=>'sometimes|integer|between:1900,2199'])->validate();
        $result=R19Store::commit($a,'r29:employee:'.$id,$in,function($tx)use($a,$id,$in){
            $p=Employee::findOrFail($id);$s=self::state($id);$d=$p->document();$action=$in['action'];
            abort_unless(self::allowed($a,$p),403);
            abort_if(!empty($d['archivedAt']),422,'الموظف مؤرشف. استعد الملف أولاً قبل أي تعديل.');
            abort_unless($p->version===(int)$in['_version']&&(int)$s['_version']===(int)$in['workflowVersion'],409,'الملف تغير. حدّثه قبل الحفظ.');
            if($action==='clock')abort_unless((string)$a->employee_id===$id&&R19HrAccess::activeEmployee($p)&&in_array(Rules::mode($d),['flexible','daily'],true),403,'بدء وإنهاء الدوام الذاتي للموظف المعفى المخول فقط.');
            elseif($action==='evaluate')abort_unless(self::canEvaluate($a,$p),403,'التقييم لمدير قسم الموظف ولا يعتمد الموظف تقييم نفسه.');
            else abort_unless(self::hr($a,in_array($action,['settlement-decision','certificate','certificate-revoke'],true)?'approve':'edit'),403);
            $changed=false;$extra=[];
            if($action==='profile'){
                $v=Validator::make($in,['profile'=>'required|array','profile.name'=>'required|string|max:200','profile.department'=>'present|nullable|string|max:200',
                    'profile.jobTitle'=>'nullable|string|max:200','profile.hireDate'=>'nullable|date_format:Y-m-d','profile.employmentStatus'=>'required|in:draft,active,inactive,resigned',
                    'profile.attendanceMode'=>'required|in:fingerprint,flexible,daily','profile.workdayHours'=>'nullable|numeric|gt:0|max:24','profile.zktecoEnrollmentNumber'=>'nullable|string|max:100',
                    'profile.probationEndDate'=>'nullable|date_format:Y-m-d','profile.probationMonths'=>'nullable|numeric|min:0|max:24','profile.annualLeaveDays'=>'nullable|numeric|min:0|max:366',
                    'profile.resignationDate'=>'nullable|date_format:Y-m-d','profile.resignationReason'=>'nullable|string|max:2000'])->validate()['profile'];
                $allowed=['name','department','jobTitle','hireDate','employmentStatus','attendanceMode','workdayHours','zktecoEnrollmentNumber','probationEndDate','probationMonths','annualLeaveDays','resignationDate','resignationReason'];
                $before=array_intersect_key($d,array_flip(['department','jobTitle','hireDate','employmentStatus']));
                $d=array_merge($d,array_intersect_key($v,array_flip($allowed)));$d['active']=$d['employmentStatus']==='active';
                if(($d['department']??'')!=='')abort_unless(in_array($d['department'],BusinessRules::departments(),true),422,'القسم غير معروف.');
                if(($d['employmentStatus']??'')==='active'&&($p->data['employmentStatus']??'active')==='resigned')abort(422,'استخدم إعادة التوظيف لحفظ فترة الخدمة السابقة.');
                $after=array_intersect_key($d,array_flip(array_keys($before)));if($before!==$after)foreach($s['career']??[]as$event)abort_if(($event['status']??'')==='scheduled',409,'ألغِ التغيير الوظيفي المجدول قبل تغيير بياناته الأساسية.');if($before!==$after)$s['career'][]=['id'=>(string)Str::ulid(),'type'=>'profile','effectiveDate'=>self::today(),'before'=>$before,'after'=>$after,'by'=>$a->name,'at'=>now()->toIso8601String(),'status'=>'applied'];$changed=true;
            }elseif($action==='schedule'){
                $schedule=Rules::schedule($in['schedule']??[]);$v=Validator::make($in,['effectiveFrom'=>'required|date_format:Y-m-d','effectiveTo'=>'nullable|date_format:Y-m-d|after_or_equal:effectiveFrom','reason'=>'required|string|max:2000'])->validate();
                $schedule+=['id'=>(string)Str::ulid(),'effectiveFrom'=>$v['effectiveFrom'],'effectiveTo'=>$v['effectiveTo']??null,'reason'=>$v['reason'],'by'=>$a->name,'at'=>now()->toIso8601String()];
                $s['schedules'][]=$schedule;
            }elseif($action==='clock'){
                $kind=$in['kind']??'';abort_unless(in_array($kind,['start','end'],true),422);$time=now()->timezone(config('app.timezone','Asia/Damascus'));
                $location = self::fieldClockNeedsLocation($d) ? self::punchLocation($in, $time->toIso8601String()) : null;
                if($kind==='start'){
                    abort_if(!empty($s['openShift']),409,'لديك دوام مفتوح. أنهه أولاً أو اطلب تصحيحاً من الموارد البشرية.');
                    $record=['id'=>'R29-'.Str::ulid(),'date'=>$time->format('Y-m-d'),'inTime'=>$time->format('H:i'),'outTime'=>null,'startAt'=>$time->toIso8601String(),'endAt'=>null,'source'=>'self_r29','status'=>'approved','breakMinutes'=>0,'createdBy'=>$a->name];
                    $attendanceId = $record['id'];
                    $s['openShift']=['id'=>$attendanceId,'startAt'=>$record['startAt']];$d['attendanceRecords'][]=$record;
                }else{
                    abort_unless(!empty($s['openShift']),409,'لا يوجد دوام مفتوح.');$found=false;
                    $attendanceId = $s['openShift']['id'];
                    foreach($d['attendanceRecords']as&$record)if(($record['id']??'')===$attendanceId){$found=true;$start=new \DateTimeImmutable($record['startAt']);$seconds=$time->getTimestamp()-$start->getTimestamp();
                        abort_unless($seconds>=0&&$seconds<=24*3600,422,'الدوام تجاوز 24 ساعة أو وقته غير صالح؛ يلزم تصحيح HR بسبب موثق.');
                        $record['endAt']=$time->toIso8601String();$record['outTime']=$time->format('H:i');$record['outDate']=$time->format('Y-m-d');$record['hours']=round($seconds/3600,4);$record['updatedBy']=$a->name;break;}unset($record);
                    abort_unless($found,409,'سجل الدوام غير موجود.');$s['openShift']=null;
                }
                if ($location !== null) $s['attendanceLocations'][$attendanceId][$kind === 'start' ? 'in' : 'out'] = $location;
                $changed=true;
            }elseif($action==='attendance-correction'){
                $v=Validator::make($in,['attendanceId'=>'required|string','startAt'=>'required|date','endAt'=>'required|date|after:startAt|before_or_equal:now','reason'=>'required|string|min:3|max:2000'])->validate();$found=false;
                $start=\Carbon\Carbon::parse($v['startAt'])->timezone(config('app.timezone'));$end=\Carbon\Carbon::parse($v['endAt'])->timezone(config('app.timezone'));abort_unless($end->getTimestamp()-$start->getTimestamp()<=24*3600,422,'المدة تتجاوز 24 ساعة.');
                foreach($d['attendanceRecords']??[]as$i=>$r)if(($r['id']??'')===$v['attendanceId']){$found=true;$s['attendanceCorrections'][]=['before'=>$r,'reason'=>$v['reason'],'by'=>$a->name,'at'=>now()->toIso8601String()];
                    $d['attendanceRecords'][$i]=array_merge($r,['date'=>$start->format('Y-m-d'),'inTime'=>$start->format('H:i'),'outTime'=>$end->format('H:i'),'outDate'=>$end->format('Y-m-d'),'startAt'=>$start->toIso8601String(),'endAt'=>$end->toIso8601String(),'hours'=>round(($end->getTimestamp()-$start->getTimestamp())/3600,4),'correctionReason'=>$v['reason'],'correctedBy'=>$a->name,'correctedAt'=>now()->toIso8601String(),'status'=>'approved']);break;}
                abort_unless($found,404);if(($s['openShift']['id']??'')===$v['attendanceId'])$s['openShift']=null;$changed=true;
            }elseif($action==='previous-leave'){
                $v=Validator::make($in,['year'=>'required|integer|between:1900,2199','days'=>'required|numeric|min:0|max:366','throughDate'=>'required|date_format:Y-m-d|before_or_equal:today','reason'=>'required|string|min:3|max:2000'])->validate();
                abort_unless(substr($v['throughDate'],0,4)===(string)$v['year'],422,'التاريخ يجب أن يكون ضمن السنة المحددة.');
                $s['leaveHistory'][]=['year'=>$v['year'],'before'=>$d['leaveUsedBeforeSystemByYear'][(string)$v['year']]??null,'after'=>$v,'by'=>$a->name,'at'=>now()->toIso8601String()];
                $d['leaveUsedBeforeSystemByYear'][(string)$v['year']]=['days'=>(float)$v['days'],'throughDate'=>$v['throughDate'],'reason'=>$v['reason'],'by'=>$a->name,'at'=>now()->toIso8601String()];$changed=true;
            }elseif($action==='criteria'){
                $title=trim((string)($d['jobTitle']??''));abort_unless($title!=='',422,'حدد المسمى الوظيفي أولاً.');$t=self::template($title);
                abort_unless((int)($in['templateVersion']??-1)===(int)$t['_version'],409,'معايير المسمى تغيرت. حدّثها قبل الحفظ.');
                $labels=Rules::criteria($in['labels']??[]);R19Store::put('r29:criteria:'.hash('sha256',$title),['jobTitle'=>$title,'labels'=>$labels,'by'=>$a->name,'at'=>now()->toIso8601String()],$t['_version']);
            }elseif($action==='evaluate'){
                $t=self::template((string)($d['jobTitle']??''));abort_unless((int)($in['templateVersion']??-1)===(int)$t['_version']&&$t['_version']>0,409,'معايير التقييم غير معدة أو تغيرت.');
                $v=Validator::make($in,['date'=>'required|date_format:Y-m-d|before_or_equal:today','note'=>'nullable|string|max:5000'])->validate();
                $e=Rules::evaluation($t['labels'],$in['scores']??[]);$e+=['id'=>'R29-'.Str::ulid(),'source'=>'r29','date'=>$v['date'],'jobTitle'=>$d['jobTitle'],'templateVersion'=>$t['_version'],'note'=>$v['note']??'','by'=>$a->name,'evaluatorId'=>(string)$a->id,'at'=>now()->toIso8601String()];
                $d['evaluationRecords'][]=$e;$changed=true;
            }elseif($action==='career'){
                $v=Validator::make($in,['type'=>'required|in:transfer,promotion,rehire','effectiveDate'=>'required|date_format:Y-m-d','department'=>'required|string','jobTitle'=>'required|string|max:200','reason'=>'required|string|min:3|max:2000'])->validate();
                abort_unless(in_array($v['department'],BusinessRules::departments(),true),422,'القسم غير معروف.');
                if($v['type']==='rehire')abort_unless(($d['employmentStatus']??'active')==='resigned'||($d['active']??true)===false,422,'الموظف نشط بالفعل.');
                foreach($s['career']??[]as$c)abort_unless(($c['status']??'')!=='scheduled',409,'يوجد تغيير وظيفي مستقبلي؛ طبّقه أو ألغِه أولاً.');
                $event=$v+['id'=>(string)Str::ulid(),'before'=>array_intersect_key($d,array_flip(['department','jobTitle','hireDate','employmentStatus','resignationDate','resignationReason'])),'by'=>$a->name,'authorizedById'=>(string)$a->id,'at'=>now()->toIso8601String(),'status'=>$v['effectiveDate']<=self::today()?'applied':'scheduled'];
                if($v['type']==='rehire'&&$event['status']==='applied')$s['servicePeriods'][]=['start'=>$d['hireDate']??null,'end'=>$d['resignationDate']??$d['lastWorkDay']??null,'reason'=>$d['resignationReason']??''];
                if($event['status']==='applied'){self::applyCareer($d,$event);$changed=true;}$s['career'][]=$event;
            }elseif($action==='career-cancel'){
                Validator::make($in,['eventId'=>'required|string','reason'=>'required|string|min:3|max:2000'])->validate();
                $found=false;foreach($s['career']as&$event)if(($event['id']??'')===($in['eventId']??'')){abort_unless($event['status']==='scheduled',409,'لا يمكن إلغاء تغيير نافذ.');$event['status']='cancelled';$event['cancelledBy']=$a->name;$event['cancelReason']=$in['reason'];$event['cancelledAt']=now()->toIso8601String();$found=true;break;}unset($event);abort_unless($found,404);
            }elseif($action==='contract-scan'){
                $v=Validator::make($in,['contractId'=>'required|string','key'=>'required|string','bothPartiesSigned'=>'accepted'])->validate();$c=EmploymentContract::findOrFail($v['contractId']);
                abort_unless((string)$c->employee_id===$id,404);$f=self::file($a,$v['key'],'r29-contract-scan',(string)$c->id);
                abort_unless(in_array($f->mime,['application/pdf','image/jpeg','image/png','image/webp'],true),422,'ارفع نسخة موقعة PDF أو صورة.');
                abort_if(isset($s['scans'][(string)$c->id]),409,'النسخة الموقعة محفوظة ولا تستبدل؛ أنشئ إصدار عقد جديد.');
                $s['scans'][(string)$c->id]=['key'=>$f->logical_key,'name'=>$f->name,'mime'=>$f->mime,'bothPartiesSigned'=>true,'by'=>$a->name,'at'=>now()->toIso8601String()];
                $old=$c->document();$cd=$c->data;$cd['status']='archived';$cd['updatedAt']=now()->toIso8601String();$cd['updatedBy']=$a->name;$c->update(['data'=>$cd,'version'=>$c->version+1,'status'=>'archived']);Audit::record($a,'hr','employment-contracts',(string)$c->id,'archive-signed-scan',$old,$c->document(),$tx);
            }elseif($action==='settlement'){
                $extra=PayrollAdjustments::create($a,$p,$in,$tx);
            }elseif($action==='settlement-decision'){
                PayrollAdjustments::decide($a,$p,$in,$tx);
            }elseif($action==='certificate'){
                $extra=Certificates::issue($a,$p,$in,$tx);
            }elseif($action==='certificate-revoke'){
                Certificates::revoke($a,$p,$in,$tx);
            }elseif($action==='ack-alert'){
                $events=Rules::probation($d,self::today());$known=array_column($events,null,'id');$key=(string)($in['alertId']??'');abort_unless(isset($known[$key]),404);$s['acknowledged'][$key]=['at'=>now()->toIso8601String(),'by'=>$a->name];
            }else abort(422,'إجراء غير معروف.');
            if($changed)self::saveEmployee($a,$p,$d,$tx,'r29-'.$action);
            R19Store::put('r29:hr:'.$id,$s,(int)$s['_version']);Audit::record($a,'hr','r29-workflow',$id,$action,null,['action'=>$action],$tx);
            return ['employeeId'=>$id]+$extra;
        });return self::read($a,$id,(int)($in['viewYear']??0))+(isset($result['certificateId'])?['issuedCertificateId'=>$result['certificateId']]:[]);
    }
    public static function applyCareer(array &$d,array $event): void {
        $d['department']=$event['department'];$d['jobTitle']=$event['jobTitle'];
        if($event['type']==='rehire'){$d['employmentStatus']='active';$d['active']=true;$d['hireDate']=$event['effectiveDate'];$d['resignationHistory'][]=['date'=>$d['resignationDate']??'','reason'=>$d['resignationReason']??'','rehiredAt'=>$event['effectiveDate']];$d['resignationDate']='';$d['resignationReason']='';}
    }
    /** Due, HR-authorized changes are applied on authenticated activity; never create a second employee. */
    public static function applyDue(): void {
        $today=self::today();$due=false;foreach(R19Store::documents('r29:hr:')as$s)foreach($s['career']??[]as$event)if(($event['status']??'')==='scheduled'&&($event['effectiveDate']??'9999')<=$today){$due=true;break;}
        if(!$due)return;
        DB::transaction(function()use($today){DB::table('app_locks')->where('id','business')->increment('revision');
            foreach(Employee::all()as$p){$s=self::state((string)$p->id);$changed=false;
                foreach($s['career']??[]as$i=>$event)if(($event['status']??'')==='scheduled'&&$event['effectiveDate']<=$today){
                    $d=$p->document();$actor=User::find($event['authorizedById']??'');$error='';
                    if(!$actor||!self::hr($actor,'edit'))$error='تغير تخويل معتمد التغيير؛ أعد المراجعة من HR.';
                    if(!empty($d['archivedAt']))$error='ملف الموظف مؤرشف؛ استعد الملف وراجع القرار قبل تطبيقه.';
                    foreach($event['before']??[]as$k=>$v)if(($d[$k]??null)!==$v){$error='تغيرت البيانات الوظيفية منذ الجدولة؛ أعد مراجعة القرار.';break;}
                    $candidate=$d;self::applyCareer($candidate,$event);
                    if($event['type']==='rehire'&&($missing=Rules::activationMissing($candidate)))$error='استكمل: '.implode('، ',$missing);
                    if($error){$s['career'][$i]['status']='needs_hr';$s['career'][$i]['error']=$error;}
                    else{
                        if($event['type']==='rehire')$s['servicePeriods'][]=['start'=>$d['hireDate']??null,'end'=>$d['resignationDate']??$d['lastWorkDay']??null,'reason'=>$d['resignationReason']??''];
                        self::saveEmployee($actor,$p,$candidate,'R29-CAREER-'.$event['id'],'r29-career-due');
                        $s['career'][$i]['status']='applied';$s['career'][$i]['appliedAt']=now()->toIso8601String();
                    }$changed=true;
                }
                if($changed)R19Store::put('r29:hr:'.$p->id,$s,$s['_version']);
            }
        },3);
    }
    public static function canReadLinked(User $a,string $resource,string $id): bool {
        if(!$a->active)return false;
        if($resource==='r29-contract-scan')return self::hr($a)&&EmploymentContract::whereKey($id)->exists();
        if($resource==='r29-certificate'){$c=R19Store::get('r29:certificate:'.$id);return $c&&(self::hr($a)||(string)$a->employee_id===($c['employeeId']??''));}
        if($resource==='r29-settlement'){$s=R19Store::get('r29:settlement:'.$id);return $s&&(self::hr($a)||(string)$a->employee_id===($s['employeeId']??''));}
        return false;
    }
}

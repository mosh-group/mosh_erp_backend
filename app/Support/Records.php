<?php
namespace App\Support;
use App\Models\{DataRecord,Employee,Setting,User,StoredFile,FileLink};
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\{DB,Validator};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Records
{
    public static function spec(string $resource): array {
        $spec=config('mosh.spec.resources.'.$resource); abort_unless($spec,404); return $spec;
    }
    public static function scalarFields(string $resource): array {
        $structured=['dueRule','cancellation','r34Approval','archiveHistory','leaveUsedBeforeSystemByYear','lines','optional','quoteBuilder','followupNotes','chatter','secondaryContacts','marketingProfile','members','history','workTiming','workChat','attachments','sharedDistribution','sharedMembers','sharedDepartments','documents','statusHistory','resignationHistory','leaveRecords','attendanceRecords','delayRecords','disciplinaryRecords','rewardRecords','evaluationRecords','payrollRecords','assets','issuedAssets','auditTrail','performanceWeights','hrNoticeArchive','offboarding','data','route','levels','payload','recipientIds','recipientSnapshots','readBy','readAtBy','clauses','signatures','commonClauses','typeClauses','customClauses','dutyClauses','conditionClauses','activities','statuses','operation18','finance21','calculation','payroll23','columns','blocks','candidates'];
        if (in_array($resource,['journal-entries','payroll-hours'],true)) $structured[]='source';
        $spec=self::spec($resource);
        foreach ($spec['example']??[] as $key=>$value) if (is_array($value)) $structured[]=$key;
        return array_values(array_diff(array_merge($spec['fields'],['id','createdAt','updatedAt']),$structured));
    }
    public static function model(string $resource): string { return 'App\\Models\\'.self::spec($resource)['model']; }
    public static function find(string $resource,string $id): DataRecord { $class=self::model($resource); return $class::findOrFail($id); }
    public static function change(User $actor,array $op,string $tx): array {
        $resource=$op['resource']; $action=$op['action'];
        if (str_starts_with($resource,'settings/')) return self::setting($actor,substr($resource,9),$op,$tx);
        $spec=self::spec($resource); $class=self::model($resource);
        $id=$op['id']??$op['data']['id']??(string)Str::ulid();
        Validator::make(['id'=>$id],['id'=>['required',fn($a,$v,$fail)=>is_scalar($v)&&preg_match('/^[\pL\pN_.:@\/-]{1,190}$/u',(string)$v)?null:$fail('معرّف غير صالح.')]])->validate();
        $row=$class::find((string)$id);
        if ($action==='create') abort_if($row,409,'السجل موجود مسبقاً.');
        else { abort_unless($row,404); abort_unless((int)($op['version']??0)===$row->version,409,'تم تعديل هذا السجل. حدث البيانات قبل إعادة المحاولة.'); }
        $old=$row?->document();
        if ($action==='delete') {
            abort_unless($spec['delete'],405);
            RecordAccess::write($actor,$resource,'delete',$old,[]);
            BusinessRules::deleting($actor,$resource,$old);
            if ($resource==='announcements') {
                $data=$row->data;$data['active']=false;$data['deletedAt']=now()->toIso8601String();$data['deletedBy']=$actor->name;
                $row->update(['data'=>$data,'version'=>$row->version+1]);
                Audit::record($actor,$spec['module'],$resource,(string)$id,'delete',$old,$row->document(),$tx);
                return ['resource'=>$resource,'id'=>$id,'before'=>$old,'after'=>$row->document(),'deleted'=>true];
            }
            try { $row->delete(); } catch (\Illuminate\Database\QueryException $e) { if (str_starts_with((string)$e->getCode(),'23')) abort(409,'السجل مرتبط ببيانات أخرى.'); throw $e; }
            FileLink::where(['resource'=>$resource,'record_id'=>(string)$id])->delete();
            Audit::record($actor,$spec['module'],$resource,(string)$id,'delete',$old,null,$tx);
            WorkdayHistory::observe($resource,$old,null);
            return ['resource'=>$resource,'id'=>$id,'before'=>$old,'after'=>null];
        }
        $input=$op['data']??[];
        $allowed=array_merge($spec['fields'],['id','_version','createdAt','createdBy','updatedAt','updatedBy']);
        $unknown=array_diff(array_keys($input),$allowed);
        if ($unknown) throw ValidationException::withMessages(['data'=>'حقول غير معروفة: '.implode(', ',$unknown)]);
        unset($input['_version']);
        $new=array_merge($old??[],$input,['id'=>$old['id']??$id]); unset($new['_version']);
        $rules=[]; foreach ($spec['required'] as $field) { if($resource==='employees'&&$field==='department'&&($new['employmentStatus']??'')==='draft') continue; $rules[$field]='required'; }
        foreach (['name','nameAr','nameEn','title','customer','employeeName','department','label','reference'] as $field) if (isset($new[$field])) $rules[$field]=($rules[$field]??'nullable').'|string|max:500';
        foreach (['active','enabled','isDefault'] as $field) if (isset($new[$field])) $rules[$field]='boolean';
        foreach (['email','personalEmail','corporateEmail'] as $field) if (!empty($new[$field])) $rules[$field]='email:rfc|max:254';
        foreach (['lines','optional','attachments','history','documents','leaveRecords','attendanceRecords','delayRecords','disciplinaryRecords','rewardRecords','evaluationRecords','payrollRecords','marketingProfile','workTiming','workChat','signatures','operation18','finance21','candidates','statuses','activities','blocks','columns','data','route','levels'] as $field) if (isset($new[$field])) $rules[$field]='array|max:2000';
        Validator::make($new,$rules)->validate();
        abort_if(strlen(json_encode($new))>2*1024*1024,413,'حجم السجل كبير جداً.');
        if ($resource==='tasks'&&array_key_exists('assignee',$input)&&$input['assignee']===''&&!array_key_exists('assigneeId',$input)) $new['assigneeId']='';
        self::identity($resource,$new,$actor);
        RecordAccess::write($actor,$resource,$action==='create'?'create':'edit',$old,$new);
        BusinessRules::validate($actor,$resource,$old,$new);
        $now=now()->toIso8601String();
        $new['createdAt']=$old['createdAt']??$now; $new['createdBy']=$old['createdBy']??$actor->name;
        $new['updatedAt']=$resource==='work-presence'?now()->getTimestampMs():$now; $new['updatedBy']=$actor->name;
        if (isset($new['history']) && is_array($new['history'])) {
            $history=$old['history']??[];
            foreach (array_slice($new['history'],count($history)) as $entry) { if (!is_array($entry)) continue; $entry['by']=$actor->name; $entry['time']=$now; $history[]=$entry; }
            $new['history']=$history;
        }
        $row??=new $class(['id'=>(string)$id,'version'=>0]);
        $attrs=['data'=>$new,'version'=>$row->version+1,'department'=>$new['department']??$new['sourceDepartment']??null,
            'status'=>is_string($new['status']??null)?$new['status']:null];
        foreach (config('mosh.spec.relations.'.$resource,[]) as $column=>$related) {
            $keys=['employee_id'=>['employeeId','assigneeId'],'customer_id'=>['customerId'],'parent_id'=>['parentTaskId'],'quotation_id'=>['salesOrderId'],
                'task_id'=>['taskId'],'item_id'=>['itemId'],'from_warehouse_id'=>['from'],'to_warehouse_id'=>['to'],'warehouse_id'=>['warehouseId'],'request_id'=>['purchaseId','logisticsId']];
            if($resource==='tasks'&&$column==='employee_id')$keys[$column]=['assigneeId'];
            $value=null; foreach ($keys[$column]??[] as $key) if (!empty($new[$key])) { $value=(string)$new[$key]; break; }
            if (!$value&&$column==='customer_id'&&!empty($new['customer'])) $value=\App\Models\Customer::where('data->name',$new['customer'])->value('id');
            if ($column==='customer_id'&&in_array($resource,['quotations','tasks','service-contracts'],true)) abort_unless($value,422,'حدد عميلاً موجوداً.');
            if ($value) { $rc=self::model($related); abort_unless($rc::whereKey($value)->exists(),422,'السجل المرتبط غير موجود: '.$related); }
            $attrs[$column]=$value;
        }
        $row->fill($attrs)->save();
        if($resource==='payroll-hours') \App\Services\R29\PayrollAdjustments::afterHours($row->document(),$old);
        if ($resource==='employees' && config('zkteco.enabled')) app(\App\Services\ZKTeco\ZktecoService::class)->syncPrimaryMapping($row);
        self::links($actor,$resource,(string)$id,$new);
        Audit::record($actor,$spec['module'],$resource,(string)$id,$action,$old,$row->document(),$tx);
        WorkdayHistory::observe($resource,$old,$row->document());
        return ['resource'=>$resource,'id'=>$id,'before'=>$old,'after'=>$row->document()];
    }
    private static function identity(string $resource,array &$d,User $actor): void {
        if (in_array($resource,['work-presence','field-work'],true)) {
            $id=$d['employeeId']??preg_replace('/^id:/','',(string)$d['id']);
            $p=Employee::find((string)$id); abort_unless($p,422,'ملف الموظف غير موجود.');
            $d['employeeId']=$p->id; $d['name']=$p->data['name'];
        }
        if ($resource==='tasks') {
            if (empty($d['assignee'])&&empty($d['assigneeId'])) $d['assigneeId']='';
            elseif (empty($d['assigneeId'])) $d['assigneeId']=Employee::where('data->name',$d['assignee'])->value('id')??'';
            if ($d['assigneeId']??'') { $p=Employee::find($d['assigneeId']); abort_unless($p,422); $d['assignee']=$p->data['name']; }
        }
    }
    private static function links(User $actor,string $resource,string $id,array $d): void {
        $keys=[];
        $scan=function($v,bool $attachment=false) use(&$scan,&$keys) {
            if (!is_array($v)) return;
            foreach ($v as $k=>$x) {
                if (is_string($x)&&$x!==''&&(in_array($k,['storageKey','fileKey','attachmentKey'],true)||($k==='key'&&($attachment||isset($v['mime'])||isset($v['blob']))))) $keys[]=$x;
                if (is_array($x)) $scan($x,$attachment||in_array($k,['attachments','documents','files','workChat'],true));
            }
        };
        $scan($d); $keys=array_values(array_unique($keys));$files=StoredFile::whereIn('logical_key',$keys)->get();
        abort_unless(count($keys)===$files->count(),422,'أحد المرفقات غير موجود. ارفع الملف أولاً.');
        foreach ($files as $f) {
            abort_unless($f->owner_id===$actor->id||FileLink::where('file_id',$f->id)->where('resource',$resource)->where('record_id',$id)->exists()||$actor->root(),403,'المرفق ليس متاحاً لهذا السجل.');
            FileLink::firstOrCreate(['file_id'=>$f->id,'resource'=>$resource,'record_id'=>$id]);
        }
        FileLink::where(['resource'=>$resource,'record_id'=>$id])->whereNotIn('file_id',$files->pluck('id'))->delete();
    }
    private static function setting(User $actor,string $key,array $op,string $tx): array {
        $spec=config('mosh.spec.settings.'.$key); abort_unless($spec,404);
        abort_unless(RecordAccess::settings($actor,$key),403);
        abort_unless($op['action']==='update',405);
        $row=Setting::findOrFail($key); abort_unless((int)($op['version']??0)===$row->version,409);
        $value=$op['data']; abort_unless(is_array($value),422);
        if ($key==='system' && !$actor->root() && !$actor->globalPermission('systemCustomization')) {
            abort_unless($actor->globalPermission('appearanceSettings'),403);
            $appearance=['fontFamily','headingSize','bodySize','smallSize','headingColor','textColor','mutedColor','bgColor','sidebarColor','panelColor','panel2Color','fieldColor','borderColor','accentColor','accentColor2'];
            foreach (array_unique(array_merge(array_keys($row->value??[]),array_keys($value))) as $field) {
                if (($row->value[$field]??null)===($value[$field]??null)) continue;
                abort_unless(in_array($field,$appearance,true),403,'هذه الصلاحية تسمح بتعديل المظهر والخطوط فقط.');
            }
        }
        if ($key==='department-managers') foreach ($value as $dept=>$id) {abort_unless(in_array($dept,BusinessRules::departments(),true),422);if($id)abort_unless(is_string($id)&&BusinessRules::activeEmployee($id),422);}
        if ($key==='purchasing') foreach ($value as $id) abort_unless(is_string($id)&&BusinessRules::activeEmployee($id),422,'حدد موظف اعتماد موجوداً.');
        if ($key==='finance-chart') {
            $codes=array_column(json_decode(file_get_contents(resource_path('defaults.json')),true)['chart'],'code');
            foreach ($value as $account) {
                Validator::make($account,['code'=>['required','string','regex:/^\d{4,10}$/D'],'name'=>'required|string|max:200','type'=>'required|in:asset,liability,equity,income,expense'])->validate();
                abort_if(in_array($account['code'],$codes,true),422,'رمز الحساب مكرر.');$codes[]=$account['code'];
                abort_if(!empty($account['partyType'])||!empty($account['cash'])||!empty($account['system']),422,'الحساب التفصيلي حساب عام.');
            }
            foreach ($row->value as $previous) {
                $next=collect($value)->firstWhere('code',$previous['code']);
                if ($next!==$previous) foreach (\App\Models\JournalEntry::all() as $entry) abort_if(collect($entry->data['lines']??[])->contains('account',$previous['code']),409,'الحساب مستخدم في قيد مرحّل.');
            }
        }
        if ($key==='finance-links') {
            foreach (array_unique(array_merge(array_keys($row->value),array_keys($value))) as $id) {
                if (($row->value[$id]??null)===($value[$id]??null)) continue;
                $doc=FinanceRules::document((string)$id);$party=$value[$id]??'';$parts=explode(':',$party,2);
                abort_unless($doc&&count($parts)===2&&$parts[0]===$doc['kind'],422,'رابط الطرف غير صالح.');
                $class=['customer'=>\App\Models\Customer::class,'vendor'=>\App\Models\Supplier::class,'employee'=>Employee::class][$parts[0]]??null;
                abort_unless($class&&$class::whereKey($parts[1])->exists(),422,'الطرف غير موجود.');
                abort_unless($actor->administration(),403);
                abort_if(in_array($doc['status'],['posted','cancelled'],true)||\App\Models\JournalEntry::where('data->key','DOC:'.$id)->exists(),409,'رابط الطرف لمستند غير مرحل فقط.');
            }
        }
        if ($key==='print-defaults') {
            foreach (array_unique(array_merge(array_keys($row->value),array_keys($value))) as $family) if (($row->value[$family]??null)!==($value[$family]??null)) abort_unless(RecordAccess::printFamily($actor,$family),403);
            foreach ($value as $family=>$id) if ($id) abort_unless(\App\Models\PrintLayout::whereKey($id)->where('data->family',$family)->exists(),422,'قالب الطباعة لا يطابق العائلة.');
        }
        if ($key==='hr-policies') Validator::make($value,['workdayHours'=>'sometimes|numeric|between:1,24','monthlyReviewDay'=>'sometimes|integer|between:1,31','probationDefaultMonths'=>'sometimes|numeric|min:0'])->validate();
        if ($key==='employment-contract-templates') {
            Validator::make($value,['common'=>'sometimes|array','types'=>'sometimes|array','types.*'=>'array'])->validate();
            BusinessRules::clauses($value['common']??[]);foreach ($value['types']??[] as $clauses) BusinessRules::clauses($clauses);
            $value['version']=($row->value['version']??0)+1;
        }
        if ($key==='executive-report-reviews') foreach ($value as $id=>&$review) {
            abort_unless(\App\Models\ExecutiveReport::whereKey((string)$id)->exists()&&is_array($review),422,'التقرير غير موجود.');
            if (($row->value[$id]??null)!==$review) {$review['by']=$actor->name;$review['at']=now()->toIso8601String();}
        }unset($review);
        if ($key==='wisdom'&&array_key_exists('order',$value)) {
            Validator::make($value,['order'=>'array','order.*'=>'required|string|distinct|exists:wisdom,id'])->validate();
            abort_unless(array_is_list($value['order']),422);
        }
        if ($key==='wisdom') Validator::make($value,['mode'=>'required|in:daily,hours','hours'=>'required|integer|between:1,168','offset'=>'integer|min:0'])->validate();
        if ($key==='finance' && !empty($value['lockedThrough'])) Validator::make($value,['lockedThrough'=>'date_format:Y-m-d|before_or_equal:today'])->validate();
        $before=['value'=>$row->value,'_version'=>$row->version];
        $row->update(['value'=>$value,'version'=>$row->version+1]);
        $after=['value'=>$row->value,'_version'=>$row->version];
        Audit::record($actor,$spec['module'],'settings/'.$key,$key,'update',$before,$after,$tx);
        return ['resource'=>'settings/'.$key,'id'=>$key,'before'=>$before,'after'=>$after];
    }
}

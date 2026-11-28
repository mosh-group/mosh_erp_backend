<?php
namespace App\Services\R31;
use App\Models\{User,Employee,Setting,StoredFile,FileLink,StockItem,StockMovement,Task};
use App\Support\{R19Store,Audit,R19HrAccess,StockRules,RecordAccess};
use Illuminate\Support\Facades\{Validator,Crypt,DB,Hash};
use Illuminate\Support\Str;

/** Private IT registry. Vault ciphertext never enters bootstrap, audit snapshots,
 * transaction responses, account lists, employee profiles or export data. */
final class ItArchive
{
    public const DEPARTMENT='تكنولوجيا المعلومات';
    public static function allowed(User $u,string $action='view'): bool {return $u->active&&!$u->must_change&&$u->allows('it','view')&&$u->allows('it',$action);}
    public static function guard(User $u,string $action='view'): void {abort_unless(self::allowed($u,$action),403,'ليست لديك صلاحية هذا الإجراء في قسم تكنولوجيا المعلومات.');}
    private static function kind(string $kind): void {abort_unless(in_array($kind,['assets','accounts','subscriptions'],true),404);}
    private static function key(string $kind,string $id): string {self::kind($kind);abort_unless((bool)preg_match('/^[A-Za-z0-9-]{8,60}$/D',$id),422,'معرف غير صالح.');return 'r31:it:'.$kind.':'.$id;}
    private static function get(string $kind,string $id): array {return R19Store::get(self::key($kind,$id))??abort(404,'السجل غير موجود.');}
    public static function publicRecord(array $d): array {
        // Deny secret-like fields even if an old/injected private document has them.
        foreach(['password','secret','ciphertext','secretCipher','recoveryCodes','twoFactorNotes','current_password','token'] as $k)unset($d[$k]);
        return $d;
    }
    private static function stockHolder(string $itemId): string {
        foreach(StockMovement::where('item_id',$itemId)->where('data->type','issue')->get() as $m)if(empty($m->data['voided'])&&StockRules::outstanding($m->document())>0)return (string)($m->data['employeeId']??'');return '';
    }
    private static function project(string $kind,array $d): array {
        $d=self::publicRecord($d);
        if($kind==='assets'&&!empty($d['stockItemId'])){$d['employeeId']=self::stockHolder($d['stockItemId']);$d['custodySource']='warehouse';}
        return $d;
    }
    private static function listedRecords(User $user, string $kind, array $records): array
    {
        $details = $user->allows('it', 'details');
        $listFields = array_flip(['id','name','code','serial','service','username','employeeId','location','amount','currency','expiresOn','reminderDays','status','custodySource','_version']);
        return array_map(function (array $record) use ($kind, $details, $listFields) {
            $visible = self::project($kind, $record);
            return $details ? $visible : array_intersect_key($visible, $listFields);
        }, $records);
    }

    public static function index(User $user, string $kind): array
    {
        self::guard($user, 'list');
        self::kind($kind);
        return self::listedRecords($user, $kind, R19Store::documents('r31:it:'.$kind.':'));
    }

    private static function employeeDeviceCustodies(Employee $employee, array $registeredStock): array
    {
        $deviceTypes = [
            'لابتوب'=>'لابتوب', 'لاب توب'=>'لابتوب', 'laptop'=>'لابتوب',
            'موبايل'=>'موبايل', 'هاتف'=>'موبايل', 'phone'=>'موبايل', 'mobile'=>'موبايل',
            'كمبيوتر'=>'كمبيوتر', 'حاسوب'=>'كمبيوتر', 'computer'=>'كمبيوتر',
        ];
        $custodies = [];
        $assets = $employee->data['assets'] ?? [];
        if (!is_array($assets)) return [];
        foreach ($assets as $index => $asset) {
            if (!is_array($asset) || ($asset['source'] ?? '') === 'it31' || !is_string($asset['type'] ?? null)) continue;
            $type = $deviceTypes[mb_strtolower(trim($asset['type']))] ?? null;
            if ($type === null) continue;
            $warehouse = ($asset['source'] ?? '') === 'warehouse18' || !empty($asset['movementId']);
            $itemId = $asset['itemId'] ?? '';
            if ($warehouse && is_scalar($itemId) && isset($registeredStock[(string) $itemId])) continue;
            $name = $asset['name'] ?? $asset['title'] ?? $type;
            $serial = $asset['serial'] ?? $asset['number'] ?? '';

            $status = match ($asset['status'] ?? '') {
                'معادة', 'مستردة', 'returned' => 'returned',
                'تحتاج صيانة', 'maintenance' => 'maintenance',
                default => 'assigned',
            };
            $custodies[] = [
                'id' => (string) $employee->id.'/'.$index,
                'employeeId' => (string) $employee->id,
                'name' => is_string($name) && trim($name) !== '' ? $name : $type,
                'type' => $type,
                'serial' => is_scalar($serial) ? (string) $serial : '',
                'issuedDate' => is_string($asset['issuedDate'] ?? null) ? $asset['issuedDate'] : '',
                'status' => $status,
                'custodySource' => $warehouse ? 'warehouse' : 'hr',
            ];
        }
        return $custodies;
    }

    public static function overview(User $u): array
    {
        self::guard($u, 'list');
        $assetRegistry = R19Store::documents('r31:it:assets:');
        $assets = self::listedRecords($u, 'assets', $assetRegistry);
        $registeredStock = [];
        foreach ($assetRegistry as $asset) {
            if (!empty($asset['stockItemId']) && ($asset['status'] ?? '') !== 'archived') {
                $registeredStock[$asset['stockItemId']] = true;
            }
        }

        $employees = $names = $inactive = $deviceCustodies = [];
        foreach (Employee::all() as $employee) {
            if (!empty($employee->data['deletedAt'])) continue;
            $id = (string) $employee->id;
            $active = R19HrAccess::activeEmployee($employee);
            $employees[] = ['id'=>$id,'name'=>$employee->data['name']??'','department'=>$employee->data['department']??'','active'=>$active];
            $names[$id] = $employee->data['name'] ?? '';
            if (!$active) $inactive[$id] = true;
            foreach (self::employeeDeviceCustodies($employee, $registeredStock) as $custody) $deviceCustodies[] = $custody;
        }

        $accounts=self::index($u,'accounts');$subscriptions=self::index($u,'subscriptions');$today=now()->toDateString();$alerts=[];
        foreach($subscriptions as $s){if(!in_array($s['status']??'active',['active','paused'],true)||empty($s['expiresOn']))continue;$days=(int)((strtotime($s['expiresOn'].'T00:00:00Z')-strtotime($today.'T00:00:00Z'))/86400);if($days<=(int)($s['reminderDays']??14))$alerts[]=['kind'=>'subscription','id'=>$s['id'],'title'=>$s['name'],'days'=>$days,'message'=>$days<0?'اشتراك منتهٍ — راجع التجديد أو الإلغاء':'اشتراك يقترب من الانتهاء'];}
        foreach(['assets'=>array_merge($assets,$deviceCustodies),'accounts'=>$accounts] as $kind=>$items)foreach($items as $item)if(!empty($item['employeeId'])&&isset($inactive[$item['employeeId']])&&!in_array($item['status']??'',['archived','returned'],true))$alerts[]=['kind'=>$kind,'id'=>$item['id'],'title'=>$item['name'],'employee'=>$names[$item['employeeId']]??'','message'=>$kind==='assets'?'معدات مرتبطة بموظف غير نشط — راجع الاستلام':'حساب شركة مرتبط بموظف غير نشط — راجع إيقاف الوصول أو نقل المسؤولية'];
        $stock=[];foreach(StockItem::all() as $s)if(empty($s->data['deletedAt'])&&($s->data['tracking']??'')==='serial')$stock[]=['id'=>(string)$s->id,'name'=>$s->data['name']??'','serial'=>$s->data['serial']??''];
        return ['today'=>$today,'employees'=>$employees,'stockAssets'=>$stock,'assets'=>$assets,'deviceCustodies'=>$deviceCustodies,'accounts'=>$accounts,'subscriptions'=>$subscriptions,'alerts'=>$alerts,'permissions'=>['create'=>$u->allows('it','create'),'edit'=>$u->allows('it','edit'),'assign'=>$u->allows('it','assign'),'details'=>$u->allows('it','details'),'attachments'=>$u->allows('it','attachments'),'manageAccounts'=>$u->globalPermission('manageITAccounts'),'viewSecrets'=>$u->globalPermission('viewITSecrets')&&$u->allows('it','details'),'audit'=>$u->globalPermission('auditLog')]];
    }
    private static function validate(string $kind,array $in): array {
        $common=['id'=>'required|regex:/^[A-Za-z0-9-]{8,60}$/D','_version'=>'required|integer|min:0','name'=>'required|string|max:200','employeeId'=>'nullable|string|max:100','department'=>'nullable|string|max:150','notes'=>'nullable|string|max:5000','fileKeys'=>'sometimes|array|max:20','fileKeys.*'=>'string|max:190'];
        $extra=match($kind){
            'assets'=>['code'=>'required|string|max:80','category'=>'required|string|max:80','model'=>'nullable|string|max:200','serial'=>'nullable|string|max:200','specifications'=>'nullable|string|max:4000','purchasedOn'=>'nullable|date_format:Y-m-d','warrantyUntil'=>'nullable|date_format:Y-m-d','location'=>'nullable|string|max:200','stockItemId'=>'nullable|string|max:100','status'=>'required|in:available,assigned,maintenance,retired,archived'],
            'accounts'=>['service'=>'required|string|max:200','url'=>'nullable|url:http,https|max:1500','username'=>'required|string|max:250','recoveryEmail'=>'nullable|email|max:250','twoFactorEnabled'=>'required|boolean','status'=>'required|in:active,inactive,review,archived'],
            'subscriptions'=>['service'=>'required|string|max:200','url'=>'nullable|url:http,https|max:1500','plan'=>'nullable|string|max:200','accountId'=>'nullable|string|max:60','seats'=>'required|integer|min:1|max:100000','beneficiaryIds'=>'sometimes|array|max:300','beneficiaryIds.*'=>'string|max:100','amount'=>'required|numeric|min:0|max:999999999','currency'=>'required|regex:/^[A-Z]{3}$/D','billingCycle'=>'required|in:monthly,quarterly,yearly,one_time,custom','startedOn'=>'nullable|date_format:Y-m-d','expiresOn'=>'required|date_format:Y-m-d','reminderDays'=>'required|integer|min:0|max:180','autoRenew'=>'required|boolean','status'=>'required|in:active,paused,cancelled,archived']
        };
        return Validator::make($in,$common+$extra)->validate();
    }
    private static function requireEmployee(string $id,bool $active=true): void {if($id==='')return;$p=Employee::find($id);abort_unless($p&&empty($p->data['deletedAt'])&&(!$active||R19HrAccess::activeEmployee($p)),422,'اختر موظفاً موجوداً ونشطاً.');}
    private static function files(User $u,string $kind,string $id,array $keys): void {
        $resource='r31-it-'.$kind;$keys=array_values(array_unique($keys));
        $existing=FileLink::where('resource',$resource)->where('record_id',$id)->pluck('file_id')->all();
        $new=[];
        foreach($keys as $key){$f=StoredFile::where('logical_key',$key)->first();abort_unless($f,422,'المرفق غير موجود.');$linked=FileLink::where('file_id',$f->id)->get();$already=$linked->contains(fn($l)=>$l->resource===$resource&&(string)$l->record_id===$id);abort_unless($already||($f->owner_id===$u->id&&$linked->isEmpty()),403,'المرفق مرتبط بسجل آخر أو لا تملكه.');$new[]=$f->id;if(!$already)FileLink::create(['file_id'=>$f->id,'resource'=>$resource,'record_id'=>$id]);}
        FileLink::where('resource',$resource)->where('record_id',$id)->whereNotIn('file_id',$new)->delete();
    }
    public static function canReadFile(User $u,string $resource,string $id): bool {$kind=substr($resource,7);return in_array($kind,['assets','accounts','subscriptions'],true)&&self::allowed($u,'details')&&$u->allows('it','attachments')&&Setting::whereKey('r31:it:'.$kind.':'.$id)->exists();}
    private static function syncCustody(User $u,array $asset,string $tx): void {
        foreach(Employee::all() as $e){$data=$e->data;$old=$data['assets']??[];$assets=[];
            foreach($old as $a){if(($a['source']??'')==='it31'&&($a['itAssetId']??'')===$asset['id'])continue;$assets[]=$a;}
            if(empty($asset['stockItemId'])&&(string)$e->id===($asset['employeeId']??'')&&!in_array($asset['status'],['retired','archived'],true))$assets[]=['id'=>'ITASSET/'.$asset['id'],'source'=>'it31','itAssetId'=>$asset['id'],'name'=>$asset['name'],'serial'=>$asset['serial']??'','type'=>['laptop'=>'لابتوب','phone'=>'موبايل'][$asset['category']??'']??'عهدة تقنية','quantity'=>1,'issuedDate'=>substr($asset['assignedAt']??$asset['updatedAt'],0,10),'status'=>$asset['status']==='maintenance'?'تحتاج صيانة':'مسلمة','notes'=>'السجل الأصلي: قسم تكنولوجيا المعلومات'];
            if($old!==$assets){$data['assets']=$assets;$e->update(['data'=>$data,'version'=>$e->version+1]);Audit::record($u,'it','employees',(string)$e->id,'it-custody',null,['assetId'=>$asset['id']],$tx);}
        }
    }
    private static function history(array $d,User $u,string $action,string $reason,array $extra=[]): array {return array_merge($d['history']??[],[['id'=>(string)Str::ulid(),'action'=>$action,'at'=>now()->toIso8601String(),'by'=>$u->name,'actorId'=>$u->id,'reason'=>$reason]+$extra]);}
    public static function save(User $u,string $kind,array $input): array {
        self::kind($kind);self::guard($u,((int)($input['_version']??0))===0?'create':'edit');
        $secret=$input['secret']??null;unset($input['secret']);
        if($kind==='accounts')abort_unless($u->globalPermission('manageITAccounts'),403,'إدارة حسابات الشركة تحتاج صلاحية مستقلة.');
        if($secret!==null){abort_unless($kind==='accounts'&&$u->globalPermission('manageITAccounts'),403);$secret=Validator::make($secret,['password'=>'required|string|max:4096','recoveryCodes'=>'nullable|string|max:8000','twoFactorNotes'=>'nullable|string|max:2000'])->validate();$input['secretFingerprint']=hash_hmac('sha256',json_encode($secret,JSON_THROW_ON_ERROR),(string)config('app.key'));}
        $id=(string)($input['id']??'');self::key($kind,$id);$values=self::validate($kind,$input);
        R19Store::commit($u,'r31-it-'.$kind,$input,function($tx)use($u,$kind,$values,$secret,$id){
            self::guard($u,(int)$values['_version']===0?'create':'edit');if($kind==='accounts')abort_unless($u->globalPermission('manageITAccounts'),403);
            $old=R19Store::get(self::key($kind,$id));if($old)self::guard($u,'details');abort_unless((int)($old['_version']??0)===(int)$values['_version'],409,'السجل تغيّر؛ حدّث البيانات قبل الحفظ.');
            if($old)abort_unless(($old['status']??'')!=='archived',422,'أعد تفعيل السجل المؤرشف قبل تعديله.');
            $v=$values;abort_unless($v['status']!=='archived',422,'استخدم إجراء الأرشفة مع السبب.');unset($v['_version']);$v['employeeId']=$v['employeeId']??'';
            self::requireEmployee($v['employeeId'],$v['employeeId']!==($old['employeeId']??''));
            if(!empty($v['department']))abort_unless(in_array($v['department'],\App\Support\BusinessRules::departments(),true),422,'القسم غير موجود.');
            $beforeKeys=$old['fileKeys']??[];$newKeys=$v['fileKeys']??$beforeKeys;if($newKeys!==$beforeKeys)abort_unless($u->allows('it','attachments'),403);$v['fileKeys']=$newKeys;
            if($kind==='assets'){
                $v['stockItemId']=$v['stockItemId']??'';
                if($old)abort_unless($v['stockItemId']===($old['stockItemId']??''),422,'لا يمكن تبديل مرجع المستودع بعد إنشاء الجهاز.');
                if($v['stockItemId']!==''){$stock=StockItem::find($v['stockItemId']);abort_unless($stock&&($stock->data['tracking']??'')==='serial',422,'اختر أصلاً مرقماً موجوداً بالمستودع.');$v['serial']=$stock->data['serial']??'';$v['employeeId']='';}
                elseif($v['employeeId']!==($old['employeeId']??''))abort_unless($u->allows('it','assign'),403,'تسليم المعدات ونقل العهد يحتاج صلاحية الإسناد.');
                if(empty($v['stockItemId'])&&$v['employeeId']!=='')abort_unless(!in_array($v['status'],['available','retired','archived'],true),422,'استرجع الجهاز قبل إخراجه من العهدة.');
                if($v['employeeId']===''&&empty($v['stockItemId']))abort_unless($v['status']!=='assigned',422,'حدد الموظف المستلم.');
                foreach(R19Store::documents('r31:it:assets:') as $a)if($a['id']!==$id){abort_if(strcasecmp($a['code'],$v['code'])===0,422,'رقم الجهاز مستخدم.');if(!empty($v['serial']))abort_if(strcasecmp($a['serial']??'',$v['serial'])===0,422,'الرقم التسلسلي موجود؛ استخدم سجل الجهاز الحالي.');if(!empty($v['stockItemId']))abort_if(($a['stockItemId']??'')===$v['stockItemId'],422,'هذا الأصل مرتبط بسجل IT آخر.');}
                // Existing warehouse custody must keep its authoritative movement ledger.
                if(empty($v['stockItemId'])&&!empty($v['serial']))foreach(StockItem::all() as $si)abort_if(strcasecmp((string)($si->data['serial']??''),$v['serial'])===0,422,'الجهاز موجود بالمستودعات؛ اختر ربط الأصل بدلاً من إنشاء عهدة مكررة.');
                if(empty($v['stockItemId'])&&!empty($v['serial']))foreach(Employee::all() as $emp)foreach($emp->data['assets']??[] as $legacy)if(($legacy['source']??'')!=='it31')abort_if(strcasecmp((string)($legacy['serial']??''),$v['serial'])===0,422,'هذا الرقم موجود بعهدة موظف قديمة؛ راجع ربط العهدة الأصلية قبل إنشاء سجل جديد. لم يغيّر النظام العهدة القديمة.');
            }
            if($kind==='subscriptions'){
                if(!empty($v['accountId']))self::get('accounts',$v['accountId']);
                foreach($v['beneficiaryIds']??[] as $eid)self::requireEmployee($eid,false);
                if(!empty($v['startedOn']))abort_unless($v['expiresOn']>=$v['startedOn'],422,'الانتهاء يسبق بداية الاشتراك.');
            }
            $d=$v+['createdAt'=>$old['createdAt']??now()->toIso8601String(),'createdBy'=>$old['createdBy']??$u->name];$d['updatedAt']=now()->toIso8601String();
            $d['history']=self::history($old??[],$u,$old?'update':'create',(string)($v['notes']??''),['employeeId'=>$d['employeeId'],'previousEmployeeId'=>$old['employeeId']??'']);
            if($kind==='assets'){$d['assignedAt']=$d['employeeId']!==($old['employeeId']??'')?now()->toIso8601String():($old['assignedAt']??null);$d['maintenance']=$old['maintenance']??[];}
            if($kind==='subscriptions')$d['renewals']=$old['renewals']??[];
            if($kind==='accounts'){$d['hasSecret']=$old['hasSecret']??false;if($secret!==null){$key='r31:vault:'.$id;$existing=R19Store::get($key);R19Store::put($key,['ciphertext'=>Crypt::encryptString(json_encode($secret,JSON_THROW_ON_ERROR)),'updatedAt'=>now()->toIso8601String()],(int)($existing['_version']??0));$d['hasSecret']=true;}}
            R19Store::put(self::key($kind,$id),$d,(int)($old['_version']??0));self::files($u,$kind,$id,$newKeys);
            if($kind==='assets')self::syncCustody($u,$d,$tx);
            Audit::record($u,'it','r31-it-'.$kind,$id,$old?'update':'create',null,['id'=>$id,'name'=>$d['name'],'secretChanged'=>$secret!==null],$tx);
            return ['id'=>$id];
        });self::guard($u,'list');return self::project($kind,self::get($kind,$id));
    }
    public static function event(User $u,string $kind,string $id,array $in): array {
        self::guard($u,'edit');self::guard($u,'details');self::kind($kind);
        $v=Validator::make($in,['_version'=>'required|integer|min:1','action'=>'required|in:archive,restore,maintenance,renew','reason'=>'required|string|min:3|max:2000','expiresOn'=>'nullable|date_format:Y-m-d','amount'=>'nullable|numeric|min:0|max:999999999','status'=>'nullable|in:open,completed','date'=>'nullable|date_format:Y-m-d'])->validate();
        R19Store::commit($u,'r31-it-event-'.$kind.'-'.$id,$in,function($tx)use($u,$kind,$id,$v){self::guard($u,'edit');if($kind==='accounts')abort_unless($u->globalPermission('manageITAccounts'),403);$d=self::get($kind,$id);abort_unless((int)$d['_version']===(int)$v['_version'],409,'السجل تغيّر؛ حدّث البيانات.');$version=$d['_version'];unset($d['_version']);$action=$v['action'];
            if($action==='archive'){if($kind==='assets')abort_unless(empty($d['employeeId'])&&(empty($d['stockItemId'])||self::stockHolder($d['stockItemId'])===''),422,'استرجع عهدة الجهاز قبل الأرشفة.');$d['previousStatus']=$d['status'];$d['status']='archived';}
            elseif($action==='restore'){abort_unless($d['status']==='archived',422);$d['status']=$d['previousStatus']??($kind==='assets'?'available':'active');}
            else{abort_unless($d['status']!=='archived',422,'السجل مؤرشف.');
                if($action==='maintenance'){abort_unless($kind==='assets',422);$d['maintenance'][]=['id'=>(string)Str::ulid(),'date'=>$v['date']??now()->toDateString(),'reason'=>$v['reason'],'status'=>$v['status']??'open','cost'=>$v['amount']??0,'by'=>$u->name];$d['status']=($v['status']??'open')==='open'?'maintenance':(!empty($d['employeeId'])?'assigned':'available');}
                if($action==='renew'){abort_unless($kind==='subscriptions'&&!empty($v['expiresOn'])&&$v['expiresOn']>$d['expiresOn'],422,'حدد تاريخ انتهاء جديداً بعد التاريخ السابق.');$d['renewals'][]=['id'=>(string)Str::ulid(),'at'=>now()->toIso8601String(),'previousExpiry'=>$d['expiresOn'],'expiresOn'=>$v['expiresOn'],'amount'=>$v['amount']??$d['amount'],'currency'=>$d['currency'],'by'=>$u->name,'reason'=>$v['reason']];$d['expiresOn']=$v['expiresOn'];$d['amount']=$v['amount']??$d['amount'];$d['status']='active';}}
            $d['history']=self::history($d,$u,$action,$v['reason']);$d['updatedAt']=now()->toIso8601String();R19Store::put(self::key($kind,$id),$d,$version);if($kind==='assets')self::syncCustody($u,$d,$tx);Audit::record($u,'it','r31-it-'.$kind,$id,$action,null,['id'=>$id],$tx);return ['id'=>$id];
        });self::guard($u,'list');return self::project($kind,self::get($kind,$id));
    }
    public static function reveal(User $u,string $id,string $password): array {
        // A separate grant: being IT manager, HR or general administrator is not sufficient.
        self::guard($u,'details');abort_unless($u->globalPermission('viewITSecrets'),403,'لا تملك صلاحية الاطلاع على خزنة كلمات السر.');
        return DB::transaction(function()use($u,$id,$password){abort_unless(DB::table('app_locks')->where('id','business')->increment('revision')===1,503);$u->refresh();$u->unsetRelation('role');self::guard($u,'details');abort_unless($u->globalPermission('viewITSecrets'),403);
            if(!Hash::check($password,$u->password)){Audit::record($u,'it','it-vault',$id,'reveal-denied');return ['denied'=>true];}
            $d=self::get('accounts',$id);$secret=R19Store::get('r31:vault:'.$id);abort_unless($secret&&$d['hasSecret'],404,'لا توجد كلمة سر محفوظة.');$decoded=json_decode(Crypt::decryptString($secret['ciphertext']),true,16,JSON_THROW_ON_ERROR);
            Audit::record($u,'it','it-vault',$id,'reveal',null,['accountId'=>$id]);return ['secret'=>$decoded,'clearAfterSeconds'=>30];
        },3);
    }
}

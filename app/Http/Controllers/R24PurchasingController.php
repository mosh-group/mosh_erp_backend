<?php
namespace App\Http\Controllers;

use App\Models\{SupportRequest,User,Supplier};
use App\Support\{R19Store,R24PurchaseRules,Records,Audit,StockRules};
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Opt-in purchasing approval workflow. All existing non-R24 purchases are untouched. */
final class R24PurchasingController extends Controller
{
    private const POLICY_KEY = '_r24_purchasing_categories';
    private static function configure(User $u): bool { return $u->active && ($u->root() || ($u->administration() && $u->allows('admin','edit'))); }
    private static function purchaser(User $u): bool { return $u->active && ($u->root() || $u->allows('purchases','edit')); }
    private static function observer(User $u): bool { return $u->active && ($u->root() || $u->administration() || $u->allows('admin','view')); }
    private static function policy(): array { return R19Store::get(self::POLICY_KEY)??['items'=>[],'_version'=>0]; }
    private static function projection(SupportRequest $r): array {
        $d=$r->document();$m=$d['operation18']??[];$s=$m['r24']??[];
        return ['id'=>(string)$r->id,'_version'=>$r->version,'requestNo'=>$d['requestNo']??'','title'=>$d['title']??'',
            'department'=>$d['sourceDepartment']??'','ownerId'=>$d['createdById']??'','details'=>$d['details']??'',
            'policyId'=>$s['policyId']??'','policyName'=>$s['policyName']??'','stage'=>$s['stage']??'',
            'phase'=>$m['phase']??'new','approverId'=>R24PurchaseRules::expected($s),
            'requiresQuoteSelection'=>(($s['stage']??'')==='purchase_pending' && (int)($s['position']??0)===count($s['purchaseApprovers']??[])-1),
            'recommendation'=>$m['recommendation']??'','lines'=>$m['lines']??[],'quotes'=>$m['quotes']??[],
            'receipts'=>$m['receipts']??[],'selectedQuoteId'=>$s['selectedQuoteId']??null,
            'approval'=>$m['approval']??null,'history'=>$s['history']??[],
            'paidAmount'=>$m['paidAmount']??null,'submittedVersion'=>$m['submittedVersion']??null];
    }
    private static function read(User $u, SupportRequest $r): bool {
        $d=$r->data??[];$m=$d['operation18']??[];$s=$m['r24']??[];
        return self::observer($u)||self::purchaser($u)||
            in_array((string)($d['createdById']??''),[(string)$u->id,(string)($u->employee_id??'')],true)||
            in_array((string)$u->id,array_merge($s['quotationApprovers']??[],$s['purchaseApprovers']??[]),true);
    }
    private static function managed(string $id): SupportRequest {
        $row=SupportRequest::findOrFail($id);
        abort_unless(($row->data['type']??'')==='purchase'&&!empty($row->data['operation18']['r24']['policyId']),404,'ليس طلب شراء ضمن نظام الموافقات الجديد.');
        return $row;
    }
    public function categories(Request $r) {
        $u=$r->user();abort_unless($u->active,403);
        $p=self::policy();
        $all=self::configure($u);
        $items=array_values(array_filter($p['items']??[],fn($x)=>$all||($x['active']??false)));
        return $this->ok(['items'=>$items,'_version'=>$p['_version'],'canConfigure'=>$all]);
    }
    public function approvers(Request $r) {
        abort_unless(self::configure($r->user()),403);
        return $this->ok(['items'=>User::where('active',true)->orderBy('name')->get()
            ->map(fn($u)=>['id'=>(string)$u->id,'name'=>(string)$u->name,'department'=>(string)$u->department])->all()]);
    }
    public function saveCategories(Request $r) {
        $u=$r->user();abort_unless(self::configure($u),403);
        $v=$r->validate(['items'=>'required|array|max:70','version'=>'required|integer|min:0',
            'requestId'=>'required|string|min:8|max:128']);
        $items=[];$ids=[];
        foreach ($v['items'] as $source) {
            abort_unless(is_array($source),422);
            try {$policy=R24PurchaseRules::validatePolicy($source);} catch (\DomainException $e) {abort(422,$e->getMessage());}
            abort_if(isset($ids[$policy['id']]),422,'نوع شراء مكرر.');$ids[$policy['id']]=true;
            foreach(array_merge($policy['quotationApprovers'],$policy['purchaseApprovers']) as $uid)
                abort_unless(User::whereKey($uid)->where('active',true)->exists(),422,'اختر حساب مدير فعّال لكل مرحلة.');
            $items[]=$policy;
        }
        $res=R19Store::commit($u,'r24.purchasing.config',$v,function($tx)use($u,$v,$items){
            $old=self::policy();abort_unless($old['_version']===$v['version'],409,'تغيرت إعدادات الاعتمادات؛ حدّث الصفحة.');
            $saved=R19Store::put(self::POLICY_KEY,['items'=>$items],$old['_version']);
            Audit::record($u,'purchases','r24-purchase-categories',self::POLICY_KEY,'update',$old,$saved,$tx);
            return ['_version'=>$saved['_version']];
        });return $this->ok($res);
    }
    public function create(Request $r) {
        $u=$r->user();abort_unless($u->active&&($u->root()||$u->allows('purchases','create')),403,'زر طلب الشراء غير مفعّل لحسابك.');
        $v=$r->validate(['categoryId'=>'required|string|max:48','title'=>'required|string|max:200',
          'details'=>'required|string|max:10000','neededDate'=>'nullable|date_format:Y-m-d',
          'lines'=>'required|array|min:1|max:100','lines.*.name'=>'required|string|max:500',
          'lines.*.qty'=>'required|numeric|gt:0','lines.*.unit'=>'required|string|max:80',
          'lines.*.kind'=>'required|in:goods,service','lines.*.spec'=>'nullable|string|max:2000',
          'taskId'=>'nullable|string|max:190','customer'=>'nullable|string|max:200',
          'location'=>'nullable|string|max:250','priority'=>'nullable|in:normal,high,urgent',
          'requestId'=>'required|string|min:8|max:128']);
        $res=R19Store::commit($u,'r24.purchasing.create',$v,function($tx)use($u,$v){
            abort_unless($u->root()||$u->allows('purchases','create'),403,'ليس لديك صلاحية طلب الشراء.');
            $cat=collect(self::policy()['items']??[])->firstWhere('id',$v['categoryId']);
            abort_unless($cat && ($cat['active']??false),422,'نوع الشراء غير فعال.');
            $cat=\App\Services\R34\Hierarchy::purchasePolicy($u,$cat);
            $lines=[];foreach($v['lines'] as $i=>$line) $lines[]=[
                'id'=>'R24-L'.($i+1),'name'=>trim($line['name']),'qty'=>(float)$line['qty'],
                'unit'=>trim($line['unit']),'kind'=>$line['kind'],'spec'=>trim($line['spec']??'')];
            $doc=['type'=>'purchase','title'=>trim($v['title']),'details'=>trim($v['details']),
                'sourceDepartment'=>$u->department,'destinationDepartment'=>'المشتريات',
                'taskId'=>$v['taskId']??'','customer'=>$v['customer']??'',
                'priority'=>$v['priority']??'normal','location'=>$v['location']??'',
                'neededDate'=>$v['neededDate']??'','status'=>'new','operation18'=>[
                   'phase'=>'new','version'=>1,'lines'=>$lines,'quotes'=>[],'receipts'=>[],
                   ],
            ];
            // A verified, request-scoped marker permits the generic Records
            // transaction to create this one new controlled purchase. Client
            // payload cannot set HTTP request attributes.
            request()->attributes->set('_r24_purchase_create_verified',true);
            try {$change=Records::change($u,['resource'=>'support-requests','action'=>'create','data'=>$doc],$tx);}
            finally {request()->attributes->remove('_r24_purchase_create_verified');}
            $row=SupportRequest::findOrFail($change['id']);$initial=$row->document();$state=$row->data;
            $state['operation18']['r24']=R24PurchaseRules::initial($cat);
            if(isset($cat['_r34Hierarchy'])) $state['operation18']['r24']=\App\Services\R34\Hierarchy::mergePurchaseState($state['operation18']['r24'],$cat['_r34Hierarchy']);
            $row->data=$state;$row->version++;$row->save();
            Audit::record($u,'purchases','support-requests',(string)$row->id,'r24-start',$initial,$row->document(),$tx);
            return ['id'=>$change['id']];
        });return $this->ok(self::projection(self::managed($res['id'])),201);
    }
    public function queue(Request $r) {
        $u=$r->user();abort_unless($u->active,403);
        $rows=[];
        foreach(SupportRequest::orderBy('created_at','desc')->get() as $x) {
            if(empty($x->data['operation18']['r24'])||!self::read($u,$x))continue;
            $rows[]=self::projection($x);
        }
        return $this->ok(['items'=>$rows]);
    }
    public function decision(Request $r,string $id) {
        $u=$r->user();abort_unless($u->active,403);
        $v=$r->validate(['action'=>'required|in:approve,reject,return','note'=>'nullable|string|max:4000',
            'quoteId'=>'nullable|string|max:190','version'=>'required|integer|min:1','requestId'=>'required|string|min:8|max:128']);
        $res=R19Store::commit($u,'r24.purchasing.decision.'.$id,$v,function($tx)use($u,$v,$id){
            $row=SupportRequest::lockForUpdate()->findOrFail($id);abort_unless(!empty($row->data['operation18']['r24']['policyId']),404);$d=$row->data;$old=$row->document();
            abort_unless($row->version===(int)$v['version'],409,'تغير الطلب؛ حدّث الصفحة.');
            $m=$d['operation18'];$s=$m['r24'];
            if(isset($s['r34Hierarchy']))abort_if((string)($s['r34Hierarchy']['requesterUserId']??'')===(string)$u->id,403,'لا تعتمد طلب الشراء الذي قدمته بنفسك.');
            try {$next=R24PurchaseRules::decide($s,(string)$u->id,$v['action'],$v['note']??'', $v['quoteId']??null);} catch(\DomainException $e){abort(422,$e->getMessage());}
            $m['r24']=$next;
            if ($next['stage']==='pricing' && ($s['stage']??'')==='quotation_pending') $m['phase']='pricing';
            if ($next['stage']==='approved' && ($s['stage']??'')==='purchase_pending') {
                // Quote, supplier, quantities, currencies and totals MUST be validated
                // independently of client data before marking the existing operation approved.
                $qid=(string)$next['selectedQuoteId'];
                $quote=collect($m['quotes']??[])->firstWhere('id',$qid);
                abort_unless($quote && empty($quote['needsReview']),422,'اختر عرضاً صالحاً من العروض المسجّلة.');
                abort_if(!empty($quote['validUntil'])&&$quote['validUntil']<now()->toDateString(),422,'انتهت صلاحية عرض المورد.');
                abort_unless(($m['submittedVersion']??0)===($m['version']??1),422,'العرض لا يخص نسخة الطلب المعتمدة.');
                $supplier=Supplier::find((string)($quote['supplierId']??''));
                abort_unless($supplier && ($supplier->data['active']??true)!==false,422,'المورد غير موجود أو غير فعّال.');
                $net=0.0;$tax=0.0;
                foreach($m['lines']??[] as $line) {
                    $p=collect($quote['lines']??[])->firstWhere('lineId',$line['id']??'');
                    abort_unless($p&&abs((float)($p['qty']??0)-(float)$line['qty'])<0.0000001&&
                        ($p['unit']??'')===($line['unit']??'')&&($p['kind']??'goods')===($line['kind']??'goods'),422,'بيانات العرض لا تطابق الطلب.');
                    $price=(float)($p['price']??-1);$discount=(float)($p['discount']??0);$vat=(float)($p['tax']??0);
                    abort_unless($price>=0&&$discount>=0&&$discount<=100&&$vat>=0&&$vat<=100,422,'قيم الأسعار غير صالحة.');
                    $base=(float)$p['qty']*$price*(1-$discount/100);$net+=$base;$tax+=$base*$vat/100;
                }
                $shipping=(float)($quote['shipping']??0);abort_unless($shipping>=0,422);
                $m['approval']=['quoteId'=>$qid,'quote'=>$quote,'by'=>(string)$u->name,'byId'=>$u->employee_id?:$u->id,
                  'at'=>now()->toIso8601String(),'total'=>['net'=>$net,'tax'=>$tax,'shipping'=>$shipping,'total'=>$net+$tax+$shipping]];
                $m['phase']='approved';
            }
            if ($next['stage']==='rejected') $m['phase']='cancelled';
            if ($next['stage']==='returned' && ($s['stage']??'')==='purchase_pending') $m['phase']='returned';
            $d['operation18']=$m;$d['updatedAt']=now()->toIso8601String();$d['updatedBy']=$u->name;
            $row->data=$d;$row->version++;
            $row->status=(string)($m['phase']??'new');$row->save();
            Audit::record($u,'purchases','support-requests',(string)$row->id,$v['action'],$old,$row->document(),$tx);
            return ['id'=>$id];
        });return $this->ok(self::projection(self::managed($res['id'])));
    }
    public function resubmit(Request $r,string $id) {
        $u=$r->user();$v=$r->validate(['version'=>'required|integer|min:1','details'=>'nullable|string|max:10000',
            'requestId'=>'required|string|min:8|max:128']);
        $res=R19Store::commit($u,'r24.purchasing.resubmit.'.$id,$v,function($tx)use($u,$v,$id){
            $row=SupportRequest::lockForUpdate()->findOrFail($id);abort_unless(!empty($row->data['operation18']['r24']['policyId']),404);$old=$row->document();$d=$row->data;
            abort_unless(in_array((string)($d['createdById']??''),[(string)$u->id,(string)($u->employee_id??'')],true)||self::purchaser($u),403);
            abort_unless($row->version===(int)$v['version'],409);
            try{$next=R24PurchaseRules::resubmit($d['operation18']['r24']);}catch(\DomainException $e){abort(422,$e->getMessage());}
            $m=$d['operation18'];$m['r24']=$next;$m['phase']='new';
            unset($m['approval']);$m['quotes']=[];$m['version']=($m['version']??1)+1;
            if(isset($v['details']))$d['details']=trim($v['details']);
            $d['operation18']=$m;$row->data=$d;$row->version++;$row->status='new';$row->save();
            Audit::record($u,'purchases','support-requests',$id,'resubmit',$old,$row->document(),$tx);
            return ['id'=>$id];
        });return $this->ok(self::projection(self::managed($res['id'])));
    }
    public function accounting(Request $r) {
        $u=$r->user();abort_unless($u->active&&($u->root()||$u->allows('accounting','view')),403);
        $items=[];foreach(SupportRequest::orderBy('created_at','desc')->get() as $r){
            $d=$r->data??[];$m=$d['operation18']??[];
            if(empty($m['r24'])||empty($m['receipts']))continue;
            $items[]=['id'=>$r->id,'title'=>$d['title']??'','supplierId'=>$m['approval']['quote']['supplierId']??'',
                'receipts'=>$m['receipts'],'total'=>$m['approval']['total']['total']??null,
                'paid'=>$m['paidAmount']??0,'phase'=>$m['phase']??''];
        }
        return $this->ok(['items'=>$items,'readOnly'=>true,'note'=>'إحالة معلومات للمحاسبة فقط؛ لا يُنشئ دفعة أو قيداً محاسبياً تلقائياً.']);
    }
}

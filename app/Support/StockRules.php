<?php
namespace App\Support;
use App\Models\{User,StockItem,StockMovement,Warehouse,Reservation,SupportRequest,Supplier,Employee,Setting};
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
class StockRules
{
    private static function need(bool $ok,string $message): void { BusinessRules::need($ok,$message); }
    public static function balance(string $item,string $warehouse): float {
        $sum=0; foreach (StockMovement::where('item_id',$item)->get() as $m) { $d=$m->data; if (!empty($d['voided'])) continue; if (($d['to']??'')===$warehouse) $sum+=$d['qty']; if (($d['from']??'')===$warehouse) $sum-=$d['qty']; } return round($sum,6);
    }
    public static function outstanding(array $move): float {
        $returned=StockMovement::where('data->refMoveId',(string)$move['id'])->get()->filter(fn($x)=>empty($x->data['voided'])&&($x->data['type']??'')==='return')->sum(fn($x)=>$x->data['qty']);
        return max(0,$move['qty']-$returned);
    }
    private static function stock(User $u,string $warehouse): bool {
        return $u->root()||$u->allows('logistics','edit')||$u->allows('warehouses','edit')||(($w=Warehouse::find($warehouse))&&in_array((string)($w->data['managerId']??''),[(string)$u->employee_id,$u->id],true));
    }
    public static function validate(User $u,string $resource,?array $old,array &$d): void {
        if ($resource==='stock-items') {
            Validator::make($d,['tracking'=>'required|in:serial,returnable,consumable','owner'=>'required|in:company,customer','minQty'=>'numeric|min:0'])->validate();
            if ($d['tracking']==='serial') self::need(!empty($d['serial']),'الرقم التسلسلي مطلوب.');
            if ($d['owner']==='customer') self::need(!empty($d['customer'])&&\App\Models\Customer::where('data->name',$d['customer'])->exists(),'حدد مالك أمانة موجوداً.');
            foreach (['serial','barcode'] as $key) if (!empty($d[$key])) self::need(!StockItem::where('id','!=',(string)$d['id'])->get()->contains(fn($r)=>mb_strtolower($r->data[$key]??'')===mb_strtolower($d[$key])),'الرقم التسلسلي أو الباركود مستخدم.');
            if ($old&&StockMovement::where('item_id',(string)$d['id'])->exists()) foreach (['tracking','owner','customer','serial','unit'] as $key) self::need(($old[$key]??'')===($d[$key]??''),'لا يمكن تغيير خصائص صنف له حركات.');
        }
        if ($resource==='warehouses') {
            $d['kind']??='general';
            Validator::make($d,['kind'=>'required|in:general,equipment,internal,customer','managerId'=>'nullable|exists:employees,id'])->validate();
            if (!empty($d['managerId'])) self::need(BusinessRules::activeEmployee($d['managerId']),'مدير المستودع غير فعال.');
            self::need(!Warehouse::where('id','!=',(string)$d['id'])->where('data->name',$d['name'])->exists(),'اسم المستودع مستخدم.');
            if (($d['active']??true)===false) {
                foreach (StockItem::all() as $item) self::need(self::balance($item->id,(string)$d['id'])==0,'المستودع يحتوي رصيداً.');
                self::need(!Reservation::where('warehouse_id',(string)$d['id'])->whereIn('status',['reserved','issued'])->exists(),'للمستودع حجوزات أو صرف قيد التنفيذ.');
                foreach (StockMovement::where('from_warehouse_id',(string)$d['id'])->where('data->type','issue')->get() as $move) self::need(!empty($move->data['voided'])||self::outstanding($move->document())==0,'للمستودع عهد غير معادة.');
            }
        }
        if ($resource==='stock-movements') self::move($u,$old,$d);
        if ($resource==='reservations') {
            abort_unless($u->root()||$u->allows('logistics','edit'),403);
            Validator::make($d,['qty'=>'required|numeric|gt:0|max:1000000000','start'=>'required|date','end'=>'required|date|after:start','status'=>'required|in:reserved,released,issued,completed'])->validate();
            $i=StockItem::find($d['itemId']); $w=Warehouse::find($d['warehouseId']); $r=SupportRequest::find($d['logisticsId']);
            self::need((bool)($i&&$w&&$r),'مورد الحجز أو طلبه غير موجود.');
            self::need(($i->data['active']??true)&&($w->data['active']??true),'الصنف والمستودع يجب أن يكونا فعالين.');
            self::need(($r->data['type']??'')==='logistics','الحجز يتبع طلباً لوجستياً.');
            self::need(($i->data['owner']??'')!=='customer'||($r->data['customer']??'')===($i->data['customer']??''),'أمانة العميل لا تستخدم لعميل آخر.');
            if (($i->data['tracking']??'')==='serial') self::need((float)$d['qty']===1.0,'كمية الأصل المرقم واحدة.');
        }
        if ($resource==='support-requests') self::request($u,$old,$d);
        if ($resource==='suppliers') self::need(!Supplier::where('id','!=',(string)$d['id'])->where('data->name',$d['name'])->exists(),'اسم المورد مستخدم.');
    }
    private static function move(User $u,?array $old,array &$d): void {
        if ($old) {
            self::need(empty($old['voided'])&&!empty($d['voided']),'الحركات المعتمدة لا تعدّل؛ استخدم حركة تصحيح.');
            abort_unless($u->administration(),403);
            self::need(!in_array($old['type'],['purchase_receipt','issue','return'],true),'لا يمكن عكس حركة مرتبطة باستلام شراء أو عهدة منفردة.');
            self::need(!empty($d['voidReason']),'سبب العكس مطلوب.');
            foreach ($old as $k=>$v) if (!in_array($k,['voided','voidedAt','voidedBy','voidReason','updatedAt','updatedBy','_version'],true)) self::need(($d[$k]??null)===$v,'لا يمكن تعديل حركة معتمدة.');
            $d['voidedAt']=now()->toIso8601String(); $d['voidedBy']=$u->name; return;
        }
        Validator::make($d,['type'=>'required|in:opening,receipt,purchase_receipt,transfer,issue,return,consume,customer_return,damage','qty'=>'required|numeric|gt:0|max:1000000000','date'=>'required|date_format:Y-m-d|before_or_equal:today'])->validate();
        $i=StockItem::find($d['itemId']); self::need((bool)$i&&($i->data['active']??true)!==false,'الصنف غير متاح.');
        $from=$d['from']??''; $to=$d['to']??''; $type=$d['type']; $q=(float)$d['qty'];
        foreach (array_filter([$from,$to]) as $wid) abort_unless(self::stock($u,$wid),403);
        foreach (array_filter([$from,$to]) as $wid) { $w=Warehouse::find($wid); self::need((bool)$w&&($w->data['active']??true)!==false,'المستودع غير متاح.'); }
        $item=$i->data;
        if (($item['tracking']??'')==='serial') self::need($q===1.0,'كمية الأصل المرقم واحدة.');
        if (in_array($type,['opening','receipt','purchase_receipt'],true)) self::need((bool)$to&&!$from,'حدد مستودع الاستلام فقط.');
        if ($type==='transfer') self::need((bool)$from&&(bool)$to&&$from!==$to,'اختر مستودعين مختلفين.');
        if (in_array($type,['issue','consume','customer_return','damage'],true)) self::need((bool)$from&&!$to,'حدد مستودع الصرف فقط.');
        if ($from) self::need(self::balance($i->id,$from)+1e-7>=$q,'الكمية تتجاوز رصيد المستودع.');
        if ($type==='issue') {
            self::need(Employee::whereKey($d['employeeId']??'')->exists(),'حدد مستلم العهدة.');
            self::need(($item['tracking']??'')!=='consumable','الصنف استهلاكي.');
            if (($item['owner']??'')==='customer') self::need(SupportRequest::find($d['logisticsId']??'')?->data['customer']===($item['customer']??''),'اربط مهمة تخص مالك الأمانة.');
        }
        if ($type==='consume') {
            self::need(($item['tracking']??'')==='consumable','الأصل المعاد يصرف كعهدة.');
            if (($item['owner']??'')==='customer') self::need(!empty($d['authorization']),'مرجع إذن العميل مطلوب.');
        }
        if ($type==='customer_return') self::need(($item['owner']??'')==='customer'&&!empty($d['party']),'حدد مستلم أمانة العميل.');
        if ($type==='damage') {
            $verified=\App\Services\R34\CommandContext::permits('damage',(string)$d['id']);
            if(\App\Services\R34\Hierarchy::get()['enabled'])abort_unless($verified,403,'إتلاف المواد يتطلب طلباً واعتماداً ثم تنفيذ الطلب المعتمد.');
            abort_unless($verified||$u->administration(),403);self::need(!empty($d['reason']),'سبب التالف مطلوب.');
        }
        if ($type==='return') {
            self::need((bool)$to&&!$from,'حدد مستودع الإرجاع فقط.');
            $ref=StockMovement::find($d['refMoveId']??'')?->document();
            self::need((bool)$ref&&$ref['type']==='issue'&&$ref['itemId']===$d['itemId']&&empty($ref['voided']),'مرجع عهدة غير صالح.');
            self::need($q<=self::outstanding($ref)+1e-7,'الإرجاع يتجاوز المتبقي من العهدة.');
            $d['employeeId']=$ref['employeeId']; $d['logisticsId']=$ref['logisticsId']??'';
        }
        if ($to&&Warehouse::find($to)?->data['kind']==='customer') self::need(($item['owner']??'')==='customer','مستودع أمانات الزبائن مخصص لممتلكاتهم.');
        if ($type==='purchase_receipt') {
            $purchase=SupportRequest::find($d['purchaseId']??'')?->data; self::need((bool)$purchase&&!empty($purchase['operation18']['approval']),'أمر شراء معتمد مطلوب.');
            $receipt=collect($purchase['operation18']['receipts']??[])->firstWhere('id',$d['receiptId']??'');
            $line=collect($receipt['lines']??[])->firstWhere('lineId',$d['purchaseLineId']??'');
            self::need((bool)$line&&(float)$line['qty']===$q,'الحركة لا تطابق محضر الاستلام.');
            $requested=collect($purchase['operation18']['lines']??[])->firstWhere('id',$d['purchaseLineId']??'');
            self::need($requested&&($requested['kind']??'goods')==='goods'&&($line['itemId']??'')===$d['itemId']&&($line['warehouseId']??'')===$to&&($requested['unit']??'')===($item['unit']??''),'الصنف أو المستودع أو وحدة الاستلام مختلفة.');
            self::need(!StockMovement::where('data->purchaseId',$d['purchaseId'])->where('data->receiptId',$d['receiptId'])->where('data->purchaseLineId',$d['purchaseLineId'])->exists(),'حركة الاستلام مسجلة مسبقاً.');
        }
        $d['qty']=$q; $d['at']=now()->toIso8601String(); $d['by']=$u->name; $d['byId']=$u->employee_id?:$u->id;
        $d['employeeName']=Employee::find($d['employeeId']??'')?->data['name']??'';
    }
    private static function request(User $u,?array $old,array &$d): void {
        Validator::make($d,['type'=>'required|in:purchase,logistics','quantity'=>'nullable|numeric|gt:0'])->validate();
        $m=$d['operation18']??[]; $b=$old['operation18']??[]; $phase=$m['phase']??'new'; $prev=$b['phase']??'new';
        // R24 opt-in managed purchases: the ordinary resource endpoint must never
        // forge approval state or bypass the chosen ordered approvers. Existing
        // purchases without this state are unaffected.
        if (!$old) abort_if(!empty($m['r24']), 403, 'نظام اعتماد R24 يُنشئ الطلب عبر مساره المصرح فقط.');
        // Once an active approval policy is configured, ordinary purchase creation
        // must not silently fall back to the legacy single-approver workflow.
        // Historical requests and their receipts are intentionally untouched.
        if (!$old && ($d['type']??'') === 'purchase') {
            $policies=R19Store::get('_r24_purchasing_categories')['items']??[];
            $managedCategories=collect($policies)->contains(fn($p)=>($p['active']??false)===true);
            if ($managedCategories) {
                $trusted=request()->attributes->get('_r24_purchase_create_verified',false);
                abort_unless($trusted===true, 422, 'أنشئ طلب الشراء من زر طلب الشراء الجديد لتطبيق تسلسل الاعتمادات.');
            }
        }
        if ($old && !empty($b['r24'])) {
            abort_unless(($m['r24']??null) === $b['r24'], 403, 'قرارات الاعتماد تُحفظ عبر مسار الاعتمادات فقط.');
            abort_if($phase === 'approved' && $prev !== 'approved', 403, 'الاعتماد النهائي من المدير المخوّل فقط.');
            $managedStage=$b['r24']['stage']??'';
            if ($managedStage==='quotation_pending' && $phase!==$prev)
                abort(403,'بانتظار اعتماد طلب عروض الأسعار قبل الانتقال للشراء.');
            if ($phase==='review' && $prev==='pricing') {
                abort_unless($managedStage==='pricing',403,'مرحلة عروض الأسعار لم تُعتمد بعد.');
                abort_unless(count($m['quotes']??[])>0,422,'سجّل عرض سعر واحداً على الأقل.');
                try {$m['r24']=R24PurchaseRules::enterPurchaseApproval($b['r24']);}
                catch (\DomainException $e) {abort(422,$e->getMessage());}
            }
            if ($managedStage==='purchase_pending' && $phase!==$prev)
                abort(403,'لا يمكن تجاوز ترتيب موافقات الشراء.');
        }
        if (!$old) { $d['createdById']=$u->employee_id?:$u->id; self::need(in_array($phase,['new','draft'],true),'الطلب الجديد يبدأ مسودة أو جديداً.'); }
        if ($old) {
            self::need($d['type']===$old['type'],'نوع الطلب لا يتغير.');
            if (in_array($prev,['completed','cancelled'],true)) self::need($m===$b,'الطلب مغلق.');
        }
        if ($d['type']==='purchase') {
            Validator::make($m,['lines'=>'required|array|min:1','lines.*.name'=>'required|string|max:500','lines.*.qty'=>'required|numeric|gt:0',
                'quotes'=>'sometimes|array','quotes.*.supplierId'=>'required|exists:suppliers,id','quotes.*.date'=>'required|date_format:Y-m-d',
                'quotes.*.lines.*.price'=>'required|numeric|min:0','quotes.*.lines.*.discount'=>'numeric|between:0,100','quotes.*.lines.*.tax'=>'numeric|between:0,100'])->validate();
            foreach ($m['quotes']??[] as &$quote) {
                $supplier=Supplier::find($quote['supplierId']);self::need($supplier&&($supplier->data['active']??true),'المورد غير فعال.');
                self::need(($quote['validUntil']??'')===''||$quote['validUntil']>=$quote['date'],'انتهاء العرض يسبق تاريخه.');
                self::need(!empty($quote['paymentTerms']),'شروط الدفع مطلوبة.');
                foreach ($m['lines'] as $line) { $priced=collect($quote['lines'])->firstWhere('lineId',$line['id']); self::need((bool)$priced&&abs((float)$priced['qty']-(float)$line['qty'])<1e-7&&($priced['unit']??'')===($line['unit']??'')&&($priced['kind']??'goods')===($line['kind']??'goods'),'العرض لا يطابق كمية ووحدة ونوع الطلب.'); }
            }
            unset($quote);
            if ($old&&!in_array($prev,['new','draft','pricing','returned'],true)) {
                self::need(($m['lines']??[])===($b['lines']??[])&&($m['quotes']??[])===($b['quotes']??[]),'الأسعار والمواصفات مقفلة بعد الإرسال.');
            }
            if ($phase!==$prev) {
                $map=['draft'=>['new','pricing','cancelled'],'new'=>['pricing','review','cancelled'],'pricing'=>['review','cancelled'],
                    'returned'=>['pricing','review','cancelled'],'review'=>['approved','returned','deferred','cancelled'],
                    'deferred'=>['review','approved','returned','cancelled'],'approved'=>['ordered','pricing','deferred','cancelled','partial','completed'],
                    'ordered'=>['partial','completed','pricing','deferred','cancelled'],'partial'=>['partial','completed']];
                self::need(in_array($phase,$map[$prev]??[],true),'انتقال مرحلة شراء غير صالح.');
                if (in_array($phase,['approved','returned','deferred'],true)&&in_array($prev,['review','deferred'],true)) {
                    $delegated=in_array($u->employee_id?:$u->id,Setting::valueOf('purchasing'),true);
                    abort_unless($u->administration()||$delegated,403);
                    abort_if(!$u->administration()&&($old['createdById']??'')===($u->employee_id?:$u->id),403,'لا يجوز اعتماد طلبك بتفويض.');
                }
            }
            if ($phase==='review') {
                self::need(($m['submittedVersion']??0)===($m['version']??1),'نسخة الإرسال لا تطابق الطلب.');
                if (!empty($m['requiresManagerApproval'])) self::need(($m['managerApproval']['version']??0)===($m['version']??1),'اعتماد المدير المباشر مطلوب لهذه النسخة.');
                self::need(!empty($m['recommendation']),'سبب الترشيح مطلوب.');
            }
            if (($m['managerApproval']??null)!==($b['managerApproval']??null)&&!empty($m['managerApproval'])) {
                abort_unless($u->root()||$u->manages($d['sourceDepartment']??''),403);
                $m['managerApproval']['by']=$u->name; $m['managerApproval']['byId']=$u->employee_id?:$u->id; $m['managerApproval']['at']=now()->toIso8601String();
            }
            if ($phase==='approved'&&$prev!=='approved') {
                $approval=$m['approval']??[]; $quote=collect($m['quotes']??[])->firstWhere('id',$approval['quoteId']??'');
                self::need((bool)$quote&&empty($quote['needsReview']),'اختر عرضاً محدثاً.');
                self::need(empty($quote['validUntil'])||$quote['validUntil']>=now()->toDateString(),'انتهت صلاحية عرض المورد.');
                self::need(($m['submittedVersion']??0)===($m['version']??1),'اعتماد لنسخة مختلفة.');
                $approval['quote']=$quote; $approval['by']=$u->name; $approval['byId']=$u->employee_id?:$u->id; $approval['at']=now()->toIso8601String();
                $net=0;$tax=0; foreach ($quote['lines'] as $l) { $base=$l['qty']*$l['price']*(1-($l['discount']??0)/100);$net+=$base;$tax+=$base*($l['tax']??0)/100; }
                $approval['total']=['net'=>$net,'tax'=>$tax,'shipping'=>(float)($quote['shipping']??0),'total'=>$net+$tax+(float)($quote['shipping']??0)];
                $m['approval']=$approval;
            } elseif (!empty($b['approval'])&&!in_array($phase,['pricing','returned'],true)) self::need(($m['approval']??null)===$b['approval'],'لا يمكن تعديل نسخة الاعتماد.');
            $counts=[];
            foreach ($m['receipts']??[] as $receipt) {
                self::need(!empty($receipt['receiver']),'اسم المستلم الفعلي مطلوب.');
                foreach ($receipt['lines']??[] as $line) {
                    self::need(is_numeric($line['qty']??null)&&$line['qty']>0,'كمية استلام غير صالحة.');
                    $approved=collect($m['approval']['quote']['lines']??[])->firstWhere('lineId',$line['lineId']);
                    self::need((bool)$approved,'بند الاستلام غير معتمد.');
                    $counts[$line['lineId']]=($counts[$line['lineId']]??0)+$line['qty'];
                    self::need($counts[$line['lineId']]<=$approved['qty']+1e-7,'الاستلام يتجاوز كمية أمر الشراء.');
                }
            }
            foreach ($b['receipts']??[] as $receipt) self::need(collect($m['receipts']??[])->firstWhere('id',$receipt['id'])===$receipt,'محضر الاستلام لا يعدّل أو يحذف.');
        } else {
            if ($phase==='completed') Validator::make($m,['proof'=>'required|array','proof.receiver'=>'required|string|max:200','proof.note'=>'required|string|max:20000'])->validate();
            if (in_array($phase,['scheduled','in_progress','completed'],true)) {
                Validator::make($m,['assignedId'=>'required|exists:employees,id','start'=>'required|date','end'=>'required|date|after:start'])->validate();
                foreach (SupportRequest::where('id','!=',(string)$d['id'])->get() as $other) {
                    $x=$other->data['operation18']??[];
                    if (($other->data['type']??'')==='logistics'&&in_array($x['phase']??'',['scheduled','in_progress'],true)&&($x['assignedId']??'')===$m['assignedId']) self::need($m['start']>=($x['end']??'')||$m['end']<=($x['start']??''),'الموظف محجوز خلال هذه الفترة.');
                }
                if (in_array($phase,['in_progress','completed'],true)) foreach (SupportRequest::where('data->operation18->linkedLogisticsId',(string)$d['id'])->get() as $purchase) self::need(($purchase->data['operation18']['phase']??'')==='completed','أكمل الشراء المرتبط أولاً.');
            }
        }
        $d['operation18']=$m;
    }
    public static function checkBalances(): void {
        foreach (StockItem::all() as $item) {
            $total=0;
            foreach (Warehouse::all() as $wh) {
                $balance=self::balance($item->id,$wh->id); self::need($balance>=-1e-7,'تؤدي العملية إلى رصيد مخزون سالب.'); $total+=$balance;
                $events=[];
                foreach (Reservation::where('data->itemId',$item->id)->where('data->warehouseId',$wh->id)->where('data->status','reserved')->get() as $r) {
                    $events[]=['at'=>$r->data['start'],'qty'=>(float)$r->data['qty']]; $events[]=['at'=>$r->data['end'],'qty'=>-(float)$r->data['qty']];
                }
                usort($events,fn($a,$b)=>strcmp($a['at'],$b['at'])?:($a['qty']<=>$b['qty'])); $peak=0;$current=0;
                foreach ($events as $e) { $current+=$e['qty']; $peak=max($peak,$current); }
                self::need($peak<=$balance+1e-7,'الكميات المحجوزة تتجاوز المتاح في فترة متداخلة.');
            }
            if (($item->data['tracking']??'')==='serial') {
                foreach (StockMovement::where('item_id',$item->id)->where('data->type','issue')->get() as $m) if (empty($m->data['voided'])) $total+=self::outstanding($m->document());
                self::need($total<=1.0000001,'الأصل المرقم موجود مسبقاً في المخزون أو العهدة.');
            }
        }
    }
    public static function syncAssets(User $u,array &$changes,string $tx): void {
        if (!collect($changes)->contains(fn($c)=>$c['resource']==='stock-movements')) return;
        $by=[];
        foreach (StockMovement::where('data->type','issue')->get() as $row) {
            $m=$row->document();if (!empty($m['voided'])||empty($m['employeeId'])) continue;$item=StockItem::find($m['itemId'])?->data;if (!$item) continue;$q=self::outstanding($m);
            $by[$m['employeeId']][]=['id'=>'WHASSET/'.$m['id'],'source'=>'warehouse18','movementId'=>$m['id'],'itemId'=>$m['itemId'],'warehouseId'=>$m['from'],
                'name'=>$item['name'],'type'=>'عهدة','serial'=>$item['serial']??'','quantity'=>$q,'issuedDate'=>$m['date'],'status'=>$q>0?'مسلمة':'معادة',
                'notes'=>(($item['owner']??'')==='customer'?'أمانة العميل '.($item['customer']??'').' · ':'').'السجل الأصلي في المستودعات العامة'];
        }
        foreach (Employee::all() as $p) {
            $d=$p->document();$assets=array_merge(array_values(array_filter($d['assets']??[],fn($x)=>($x['source']??'')!=='warehouse18')),$by[$p->id]??[]);
            if (($d['assets']??[])!==$assets) {$d['assets']=$assets;BusinessRules::derived($u,'employees',$p->id,$d,$changes,$tx);}
        }
    }
    public static function afterTransaction(User $u,array &$changes,string $tx): void {
        foreach (array_values($changes) as $change) {
            if ($change['resource']==='support-requests'&&$change['after']) {
                $d=$change['after'];$m=$d['operation18']??[];
                if ($d['type']==='logistics'&&in_array($m['phase']??'',['deferred','cancelled','completed'],true)) {
                    foreach (Reservation::where('request_id',(string)$d['id'])->where('status','reserved')->get() as $row) {
                        $value=$row->document();$value['status']='released';BusinessRules::derived($u,'reservations',$row->id,$value,$changes,$tx);
                    }
                }
                if ($d['type']==='purchase') foreach ($m['receipts']??[] as $receipt) {
                    Validator::make($receipt,['id'=>'required|string','date'=>'required|date_format:Y-m-d|before_or_equal:today','lines'=>'required|array|min:1','lines.*.lineId'=>'required|distinct'])->validate();
                    foreach ($receipt['lines'] as $line) {
                        $source=collect($m['lines']??[])->firstWhere('id',$line['lineId']);self::need((bool)$source,'بند الاستلام غير موجود.');
                        $moves=StockMovement::where('data->purchaseId',(string)$d['id'])->where('data->receiptId',$receipt['id'])->where('data->purchaseLineId',$line['lineId'])->get();
                        if (($source['kind']??'goods')==='service') self::need(empty($line['warehouseId'])&&empty($line['itemId'])&&$moves->isEmpty(),'الخدمة لا تدخل المخزون.');
                        elseif (!empty($line['warehouseId'])) {
                            $item=StockItem::find($line['itemId']??'');
                            self::need($item&&($item->data['unit']??'')===($source['unit']??''),'وحدة الصنف لا تطابق الاستلام.');
                            self::need($moves->count()===1,'سجل حركة استلام المطابقة في المعاملة نفسها.');
                            $move=$moves->first()->data;
                            self::need(empty($move['voided'])&&$move['type']==='purchase_receipt'&&$move['itemId']===$line['itemId']&&$move['to']===$line['warehouseId']&&abs($move['qty']-$line['qty'])<1e-7,'حركة الاستلام غير مطابقة.');
                        } else self::need($moves->isEmpty(),'الاستلام المباشر لا ينشئ مخزوناً.');
                    }
                }
            }
        }
        // Validate fulfillment after the entire batch so a reservation may precede its stock movement.
        foreach (Reservation::where('status','issued')->get() as $reservation) {
            $d=$reservation->data;$move=StockMovement::find($d['movementId']??'');
            self::need($move&&empty($move->data['voided'])&&$move->data['type']==='issue'&&$move->item_id===$d['itemId']&&$move->from_warehouse_id===$d['warehouseId']&&($move->data['logisticsId']??'')===$d['logisticsId']&&abs($move->data['qty']-$d['qty'])<1e-7,'الصرف يتطلب حركة عهدة مطابقة.');
            self::need(Reservation::where('status','issued')->where('data->movementId',$move->id)->count()===1,'الحركة مستخدمة لحجز آخر.');
        }
    }

}

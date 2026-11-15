<?php
namespace App\Http\Controllers;
use App\Models\{AuditEvent,Quotation,User};
use App\Support\{RecordAccess,Records,FinanceRules,StockRules,JournalRules,Audit};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Mail};
use Illuminate\Support\Str;
class ReportController extends Controller
{
    public function workday(Request $r) {
        $r->validate(['date'=>'nullable|date_format:Y-m-d|before_or_equal:today']);
        $date=$r->input('date') ?: now()->toDateString();
        $w=\App\Support\WorkdayReport::forUser($r->user(),$date);
        return $this->ok(['workday'=>$w,'timeZone'=>config('app.timezone'),'serverNow'=>now()->getTimestampMs()]);
    }

    private function filtered(Request $r,array $rows,bool $dates=true): array {
        $r->validate(['from'=>'nullable|date_format:Y-m-d','to'=>'nullable|date_format:Y-m-d|after_or_equal:from','q'=>'nullable|string|max:200','page'=>'sometimes|integer|min:1','perPage'=>'sometimes|integer|between:1,100','print'=>'sometimes|boolean']);
        return array_values(array_filter($rows,function($d)use($r,$dates) {
            if ($q=$r->input('q')) if (!str_contains(mb_strtolower(json_encode($d,JSON_UNESCAPED_UNICODE)),mb_strtolower($q))) return false;
            foreach (['department','employeeId','customerId','supplierId','currency','status','period'] as $k) if ($r->filled($k)) {
                $value=$d[$k]??'';
                if ($k==='customerId'&&!$value) $value=($d['kind']??'')==='customer'?($d['partyId']??''):\App\Models\Customer::where('data->name',$d['customer']??'')->value('id');
                if ($k==='supplierId'&&!$value&&($d['kind']??'')==='vendor') $value=$d['partyId']??'';
                if ((string)$value!==(string)$r->input($k)) return false;
            }
            $date=$d['date']??$d['createdDate']??substr((string)($d['createdAt']??''),0,10);
            return !$dates||(!$r->filled('from')||$date>=$r->input('from'))&&(!$r->filled('to')||$date<=$r->input('to'));
        }));
    }
    private function result(Request $r,array $rows,array $summary=[]) {
        $total=count($rows);$size=(int)$r->input('perPage',25);$page=(int)$r->input('page',1);
        if ($r->boolean('print')) { abort_if($total>10000,422,'ضيّق نطاق التقرير إلى 10000 صف أو أقل.');$out=$rows; } else $out=array_slice($rows,($page-1)*$size,$size);
        return $this->ok(['rows'=>$out,'summary'=>$summary,'generatedAt'=>now()->toIso8601String()],200,['page'=>$page,'perPage'=>$size,'total'=>$total,'lastPage'=>max(1,(int)ceil($total/$size))]);
    }
    public function dashboard(Request $r,?string $department=null) {
        if ($department) abort_unless($r->user()->allows(User::departmentApp($department),'dashboard'),403);
        $tasks=ResourceController::rows($r,'tasks');
        $tasks=array_values(array_filter($tasks,fn($t)=>$department?($t['department']??'')===$department:RecordAccess::own($r->user(),$t)));
        $tasks=$this->filtered($r,$tasks);$byStatus=[];$byDepartment=[];
        foreach ($tasks as $t) { $byStatus[$t['status']??'new']=($byStatus[$t['status']??'new']??0)+1;$dep=$t['department']??'';$byDepartment[$dep]=($byDepartment[$dep]??0)+1; }
        $overdue=count(array_filter($tasks,fn($t)=>($t['status']??'')==='in_progress'&&!empty($t['dueDate'])&&$t['dueDate']<now()->toDateString()));
        return $this->result($r,$tasks,['total'=>count($tasks),'byStatus'=>$byStatus,'byDepartment'=>$byDepartment,'overdue'=>$overdue,
            'requests'=>count(ResourceController::rows($r,'hr-requests')),'announcements'=>count(ResourceController::rows($r,'announcements'))]);
    }
    public function report(Request $r,string $report) {
        $map=['sales'=>['sales','quotations'],'design-work'=>['design','tasks'],'hr'=>['hr','employees'],'operations'=>['logistics','support-requests'],
            'inventory'=>['warehouses','stock-items'],'finance'=>['accounting','financial-documents'],'cash'=>['accounting','payments'],
            'trial-balance'=>['accounting','journal-entries'],'party-statement'=>['accounting','journal-entries'],'payroll'=>['hr','payroll-runs']];
        abort_unless(isset($map[$report]),404);[$module,$res]=$map[$report];
        abort_unless($r->user()->allows($module,'reports')||$r->user()->allows($module,'print'),403);
        if (in_array($report,['trial-balance','party-statement'],true)) return $this->ledger($r,$report);
        $rows=$report==='finance'?array_values(array_filter(FinanceRules::documents(),function($d)use($r) {
            if (str_starts_with($d['id'],'INV:')) {$invoice=\App\Models\Invoice::find(substr($d['id'],4));return $invoice&&RecordAccess::readable($r->user(),'invoices',$invoice->document());}
            return RecordAccess::readable($r->user(),'financial-documents',$d);
        })):ResourceController::rows($r,$res);
        if ($report==='design-work') $rows=array_values(array_filter($rows,fn($d)=>($d['department']??'')==='التصميم'||($d['closedFromDepartment']??'')==='التصميم'));
        if ($report==='sales') foreach ($rows as &$quote) {
            $subtotal=0;$tax=0;$cost=0;
            foreach ($quote['lines']??[] as $line) if (($line['type']??'product')==='product') {
                $base=($line['qty']??0)*($line['price']??0)*(1-($line['discount']??0)/100);$subtotal+=$base;$tax+=$base*($line['tax']??0)/100;$cost+=($line['qty']??0)*($line['cost']??0);
            }
            $quote['subtotal']=$subtotal;$quote['tax']=$tax;$quote['total']=$subtotal+$tax;$quote['margin']=$subtotal-$cost;$quote['currency']=$quote['currency']??'USD';
        }unset($quote);
        $rows=$this->filtered($r,$rows);
        if ($report==='inventory') foreach ($rows as &$item) { $item['balances']=[];foreach (ResourceController::rows($r,'warehouses') as $w) $item['balances'][$w['id']]=StockRules::balance((string)$item['id'],(string)$w['id']); } unset($item);
        $currencies=[];foreach ($rows as $d) if (!empty($d['currency'])) { $c=$d['currency'];$currencies[$c]=($currencies[$c]??0)+($report==='sales'?(float)($d['total']??0):(int)($d['total']??$d['amount']??0)); }
        return $this->result($r,$rows,['count'=>count($rows),'amountByCurrency'=>$currencies]);
    }
    private function ledger(Request $r,string $report) {
        $this->filtered($r,[]);
        $r->validate(['currency'=>'required|string|in:USD,SYP,EUR,AED,SAR,LBP,JOD,TRY,EGP','party'=>'nullable|string|max:200','account'=>'nullable|string|max:200']);
        $all=ResourceController::rows($r,'journal-entries');$rows=[];$totals=[];$opening=0;$balance=0;
        usort($all,fn($a,$b)=>strcmp($a['date'],$b['date'])?:strcmp($a['postedAt']??'',$b['postedAt']??''));
        foreach ($all as $e) {
            if ($e['currency']!==$r->input('currency')||$r->filled('to')&&$e['date']>$r->input('to')) continue;
            foreach ($e['lines'] as $line) {
                if ($r->filled('party')&&($line['party']??'')!==$r->input('party')) continue;
                if ($r->filled('account')&&$line['account']!==$r->input('account')) continue;
                $candidate=array_merge($e,$line);
                if (!$this->filtered($r,[$candidate],false)) continue;
                $delta=$line['debit']-$line['credit'];$code=$line['account'];$prior=$r->filled('from')&&$e['date']<$r->input('from');
                $totals[$code]??=['account'=>$code,'name'=>JournalRules::chart()[$code]['name']??$code,'opening'=>0,'debit'=>0,'credit'=>0,'closing'=>0];
                if ($prior) { $totals[$code]['opening']+=$delta;$opening+=$delta; } else { $totals[$code]['debit']+=$line['debit'];$totals[$code]['credit']+=$line['credit']; }
                $totals[$code]['closing']+=$delta;$balance+=$delta;
                if (!$prior) $rows[]=array_merge($line,['id'=>$e['id'],'number'=>$e['number'],'date'=>$e['date'],'currency'=>$e['currency'],'reference'=>$e['reference'],'memo'=>$e['memo'],'source'=>$e['source'],'balance'=>$balance]);
            }
        }
        return $this->result($r,$report==='trial-balance'?array_values($totals):$rows,['opening'=>$opening,'closing'=>$balance,'debit'=>array_sum(array_column($totals,'debit')),'credit'=>array_sum(array_column($totals,'credit')),'currency'=>$r->input('currency')]);
    }
    private function auditRow(Request $r,AuditEvent $e): ?array {
        if ($e->module==='security') return null;
        $spec=config('mosh.spec.resources.'.$e->resource);if (!$spec) return $r->user()->root()?['id'=>$e->id,'app'=>$e->module,'changeType'=>$e->action,'at'=>$e->created_at->toIso8601String(),'user'=>$e->actor_name,'changedAreas'=>[$e->resource],'message'=>$e->message]:null;
        $d=$e->after_data??$e->before_data??[];if (!RecordAccess::readable($r->user(),$e->resource,$d)) return null;
        $before=RecordAccess::document($r->user(),$e->resource,$e->before_data??[]);$after=RecordAccess::document($r->user(),$e->resource,$e->after_data??[]);
        $changes=[];foreach (array_unique(array_merge(array_keys($before),array_keys($after))) as $key) if (($before[$key]??null)!==($after[$key]??null)) $changes[]=['field'=>$key,'before'=>$before[$key]??null,'after'=>$after[$key]??null];
        return ['id'=>$e->id,'app'=>$e->module,'resource'=>$e->resource,'resourceId'=>$e->record_id,'changeType'=>$e->action,'changedAreas'=>[$spec['statePath']],
            'at'=>$e->created_at->toIso8601String(),'time'=>$e->created_at->toIso8601String(),'user'=>$e->actor_name,'userId'=>$e->actor_id,'message'=>$e->message,'changes'=>$changes,'before'=>$before,'after'=>$after];
    }
    public function audit(Request $r) {
        abort_unless($r->user()->globalPermission('viewArchive'),403);$query=AuditEvent::where('module','!=','security');
        foreach (['module'=>'module','resource'=>'resource','resourceId'=>'record_id','userId'=>'actor_id'] as $param=>$col) if ($r->filled($param)) $query->where($col,$r->input($param));
        if ($r->filled('from')) $query->whereDate('created_at','>=',$r->input('from'));if ($r->filled('to')) $query->whereDate('created_at','<=',$r->input('to'));
        $rows=$query->latest('created_at')->get()->map(fn($e)=>$this->auditRow($r,$e))->filter()->values()->all();
        if ($q=$r->input('q')) $rows=array_values(array_filter($rows,fn($e)=>str_contains(mb_strtolower(json_encode($e,JSON_UNESCAPED_UNICODE)),mb_strtolower($q))));
        $p=max(1,(int)$r->input('page',1));$size=min(100,max(1,(int)$r->input('perPage',25)));
        return $this->ok(array_slice($rows,($p-1)*$size,$size),200,['page'=>$p,'perPage'=>$size,'total'=>count($rows),'lastPage'=>max(1,(int)ceil(count($rows)/$size))]);
    }
    public function auditDetail(Request $r,string $id) {
        abort_unless($r->user()->globalPermission('viewArchive'),403);$e=AuditEvent::findOrFail($id);$row=$this->auditRow($r,$e);abort_unless($row,404);return $this->ok($row);
    }
    public function email(Request $r,string $id) {
        $q=Quotation::findOrFail($id);abort_unless($r->user()->allows('sales','edit')&&RecordAccess::readable($r->user(),'quotations',$q->document()),403);
        $v=$r->validate(['to'=>'required|email:rfc|max:254','subject'=>'required|string|max:250','body'=>'required|string|max:100000']);
        $key=$r->header('Idempotency-Key');abort_unless(is_string($key)&&strlen($key)>=8&&strlen($key)<=180,422);
        abort_if(in_array(config('mail.default'),['log','array'],true)&&!app()->environment('testing'),503,'اضبط خدمة البريد SMTP قبل الإرسال.');
        return DB::transaction(function ()use($r,$q,$v,$key) {
            DB::table('app_locks')->where('id','business')->increment('revision');
            $key='mail:'.$key;$hash=hash('sha256',json_encode([$q->id,$v]));$previous=DB::table('transactions')->where('actor_id',$r->user()->id)->where('idempotency_key',$key)->first();
            if ($previous) { abort_unless($previous->request_hash===$hash,409);return $this->ok(json_decode($previous->response,true)); }
            try { Mail::raw($v['body'],fn($m)=>$m->to($v['to'])->subject($v['subject'])); } catch (\Throwable $e) { report($e);abort(503,'تعذر تسليم الرسالة لخدمة البريد.'); }
            $old=$q->document();$d=$q->data;if (($d['status']??'draft')==='draft') $d['status']='sent';$d['sentAt']=now()->toIso8601String();$q->update(['data'=>$d,'version'=>$q->version+1]);
            $tx=(string)Str::ulid();Audit::record($r->user(),'sales','quotations',$q->id,'email',$old,$q->document(),$tx);
            $out=['sent'=>true,'quotation'=>$q->document()];DB::table('transactions')->insert(['id'=>$tx,'actor_id'=>$r->user()->id,'idempotency_key'=>$key,'request_hash'=>$hash,'changes'=>'[]','response'=>json_encode($out),'created_at'=>now(),'updated_at'=>now()]);return $this->ok($out);
        });
    }
}

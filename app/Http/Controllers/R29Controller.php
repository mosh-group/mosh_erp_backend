<?php
namespace App\Http\Controllers;
use App\Services\R29\{HrWorkflow,CustomerWork,Certificates};
use Illuminate\Http\Request;
final class R29Controller extends Controller
{
    private function run(callable $fn){try{return $this->ok($fn());}catch(\DomainException $e){abort(422,$e->getMessage());}}
    public function index(Request $r){return $this->run(fn()=>HrWorkflow::index($r->user()));}
    public function read(Request $r,string $id){$v=$r->validate(['year'=>'sometimes|integer|between:1900,2199']);return $this->run(fn()=>HrWorkflow::read($r->user(),$id,(int)($v['year']??0)));}
    public function create(Request $r){return $this->run(fn()=>HrWorkflow::create($r->user(),$r->all()));}
    public function mutate(Request $r,string $id){return $this->run(fn()=>HrWorkflow::mutate($r->user(),$id,$r->all()));}
    public function customers(Request $r){$v=$r->validate(['q'=>'nullable|string|max:200','status'=>'sometimes|in:active,archived','page'=>'sometimes|integer|min:1']);return $this->run(fn()=>CustomerWork::list($r->user(),$v));}
    public function tasks(Request $r,string $id){return $this->run(fn()=>CustomerWork::tasks($r->user(),$id));}
    public function archive(Request $r,string $id){return $this->run(fn()=>CustomerWork::archive($r->user(),$id,$r->all()));}
    public function adminTasks(Request $r){return $this->run(fn()=>CustomerWork::adminTasks($r->user()));}
    public function certificate(Request $r,string $id){return response(Certificates::html($r->user(),$id))->header('Content-Type','text/html; charset=UTF-8')->header('Cache-Control','private, no-store')->header('Referrer-Policy','no-referrer')->header('Content-Security-Policy',"default-src 'none'; img-src data:; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'self'");}
    public function settlement(Request $r,string $id){return response(\App\Services\R29\PayrollAdjustments::html($r->user(),$id))->header('Content-Type','text/html; charset=UTF-8')->header('Cache-Control','private, no-store')->header('Content-Security-Policy',"default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'self'");}
    public function verify(Request $r,string $token){$d=Certificates::verify($token);$e=fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');return response('<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>التحقق من شهادة موش</title><body><h1>'.($d['status']==='issued'?'شهادة مسجلة وصالحة':'شهادة ملغاة').'</h1><p>'.$e($d['number']).'</p><p>تاريخ الإصدار: '.$e(substr($d['issuedAt'],0,10)).'</p><p>التحقق من رقم الشهادة وحالتها لا يعرض بيانات الموظف الخاصة. طابق الرقم مع النسخة المقدمة.</p></body></html>')->header('Content-Type','text/html; charset=UTF-8')->header('Cache-Control','no-store')->header('Referrer-Policy','no-referrer')->header('Content-Security-Policy',"default-src 'none'; frame-ancestors 'none'; base-uri 'none'");}
}

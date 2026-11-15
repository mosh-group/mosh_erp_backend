<?php
namespace App\Http\Controllers;
use App\Services\R31\ItArchive;
use Illuminate\Http\Request;
final class R31ItController extends Controller
{
    private function secure(Request $r): void {abort_unless($r->isSecure(),426,'خزنة كلمات السر تتطلب HTTPS آمناً. اطلب من مسؤول الخادم تفعيل HTTPS؛ لا تدخل كلمات سر عبر HTTP.');}
    private function reply(mixed $v){return $this->ok($v)->header('Cache-Control','private, no-store, max-age=0')->header('Pragma','no-cache')->header('Referrer-Policy','no-referrer');}
    public function overview(Request $r){return $this->reply(ItArchive::overview($r->user()));}
    public function index(Request $r,string $kind){return $this->reply(ItArchive::index($r->user(),$kind));}
    public function save(Request $r,string $kind){if($r->has('secret'))$this->secure($r);return $this->reply(ItArchive::save($r->user(),$kind,$r->all()));}
    public function event(Request $r,string $kind,string $id){return $this->reply(ItArchive::event($r->user(),$kind,$id,$r->all()));}
    public function secret(Request $r,string $id){$this->secure($r);$v=$r->validate(['current_password'=>'required|string|max:128']);$result=ItArchive::reveal($r->user(),$id,$v['current_password']);abort_if(!empty($result['denied']),403,'كلمة مرور حساب موش غير صحيحة.');return $this->reply($result);}
}

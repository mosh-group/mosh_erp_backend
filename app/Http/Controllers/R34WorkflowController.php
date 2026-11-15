<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Services\R34\{Hierarchy,Approvals,TaskConversation,Cancellation,Deadline};
final class R34WorkflowController extends Controller
{
    public function hierarchy(Request $r){return $this->ok(Hierarchy::read($r->user()));}
    public function saveHierarchy(Request $r){return $this->ok(Hierarchy::save($r->user(),$r->all()));}
    public function inbox(Request $r){return $this->ok(Approvals::overview($r->user()));}
    public function hrDecision(Request $r){$v=$r->validate(['resource'=>'required|in:hr-requests,hr-approvals','id'=>'required|string|max:190']);return $this->ok(Approvals::decideHr($r->user(),$v['resource'],$v['id'],$r->all()));}
    public function damage(Request $r){return $this->ok(Approvals::requestDamage($r->user(),$r->all()),201);}
    public function damageDecision(Request $r,string $id){return $this->ok(Approvals::decideDamage($r->user(),$id,$r->all()));}
    public function damageExecute(Request $r,string $id){return $this->ok(Approvals::executeDamage($r->user(),$id,$r->all()));}
    public function send(Request $r){$v=$r->validate(['taskId'=>'required|string|max:190']);return $this->ok(TaskConversation::send($r->user(),$v['taskId'],$r->all()));}
    public function change(Request $r){$v=$r->validate(['taskId'=>'required|string|max:190']);return $this->ok(TaskConversation::change($r->user(),$v['taskId'],$r->all()));}
    public function chat(Request $r){$v=$r->validate(['taskId'=>'required|string|max:190']);$t=TaskConversation::task($r->user(),$v['taskId'],false)->document();$items=[];foreach($t['workChat']??[]as$m)$items[]=['id'=>$m['id']??'','canModify'=>TaskConversation::canModerate($r->user(),$t,$m)];return $this->ok(['task'=>$t,'messagePermissions'=>$items,'canCancel'=>Cancellation::manager($r->user(),$t)&&Cancellation::cancellable($t)]);}
    public function cancelRequest(Request $r){$v=$r->validate(['taskId'=>'required|string|max:190']);return $this->ok(Cancellation::request($r->user(),$v['taskId'],$r->all()),201);}
    public function cancellations(Request $r){return $this->ok(Cancellation::overview($r->user()));}
    public function code(Request $r,string $id){return $this->ok(Cancellation::code($r->user(),$id))->header('Cache-Control','no-store, private')->header('Pragma','no-cache');}
    public function confirm(Request $r,string $id){return $this->ok(Cancellation::confirm($r->user(),$id,$r->all()));}
    public function reject(Request $r,string $id){return $this->ok(Cancellation::reject($r->user(),$id,$r->all()));}
    public function cancelled(Request $r){return $this->ok(Cancellation::archive($r->user()));}
    public function deadline(Request $r){$v=$r->validate(['mode'=>'required|in:workdays,calendar_month','days'=>'nullable|integer|min:1|max:3660']);return $this->ok(Deadline::preview($v['mode'],(int)($v['days']??0)));}
}

<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Services\ZKTeco\ZktecoService;
use App\Models\ZktecoDevice;
class ZktecoBridgeController extends Controller{
 public function ingest(Request $r,ZktecoService $s){$v=$r->validate(['eventKey'=>'nullable|string|max:190','deviceId'=>'required|string|max:190','enrollmentNumber'=>'required|string|max:100','deviceTime'=>'required|date','verifyMethod'=>'nullable|integer','attState'=>'nullable|integer','workCode'=>'nullable|integer','isInvalid'=>'nullable|integer','source'=>'nullable|in:event,reconciliation']);$this->resolveDevice($v['deviceId'],$s);return $this->ok($s->ingest($v),202);}
 public function heartbeat(Request $r,ZktecoService $s){$v=$r->validate(['deviceId'=>'required|string|max:190','status'=>'nullable|string|max:40','meta'=>'nullable|array']);$d=$this->resolveDevice($v['deviceId'],$s);$d->update(['last_seen_at'=>now(),'status'=>$v['status']??'online','meta'=>$v['meta']??$d->meta]);return $this->ok(['received'=>true,'deviceId'=>$d->id]);}
 private function resolveDevice(string $deviceId,ZktecoService $s):ZktecoDevice{$d=ZktecoDevice::find($deviceId);if($d)return $d;abort_unless(hash_equals((string)config('zkteco.primary_device.id'),$deviceId),404,'Unknown ZKTeco device.');return $s->ensurePrimaryDevice();}
}

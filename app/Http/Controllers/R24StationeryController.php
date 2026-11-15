<?php
namespace App\Http\Controllers;

use App\Models\User;
use App\Support\{R19Store,Audit};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** MOSH Group's official seal: changing it requires the General Manager's approval. */
final class R24StationeryController extends Controller
{
    private const ACTIVE = '_r24_stationery_mosh_seal';
    private const PENDING = '_r24_stationery_seal_proposal_';
    private static function proposer(User $u): bool { return $u->active && $u->globalPermission('manageOfficialStationery'); }
    private static function approver(User $u): bool { return $u->active && $u->globalPermission('approveOfficialSeal'); }
    private static function summary(array $row): array {
        return ['id'=>$row['id']??'', 'status'=>$row['status']??'',
            'proposedBy'=>$row['proposedBy']??'', 'proposedAt'=>$row['proposedAt']??'',
            'decidedAt'=>$row['decidedAt']??'', 'decidedBy'=>$row['decidedBy']??'',
            'reason'=>$row['reason']??'', 'fingerprint'=>$row['fingerprint']??'', '_version'=>$row['_version']??0];
    }
    public static function current(): ?array {
        $d=R19Store::get(self::ACTIVE);return !empty($d['image'])?$d:null;
    }
    public function info(Request $r) {
        $u=$r->user();abort_unless($u->active && (self::proposer($u)||self::approver($u)),403);
        $active=self::current();$pending=[];
        foreach(R19Store::prefixed(self::PENDING) as $d) if(($d['status']??'')==='pending') $pending[]=array_merge(self::summary($d),self::approver($u)?['image'=>$d['image']??null]:[]);
        return $this->ok(['company'=>'MOSH Group','active'=>$active?array_merge(self::summary($active),['image'=>$active['image']]):null,
            'pending'=>$pending,'canPropose'=>self::proposer($u),'canApprove'=>self::approver($u)]);
    }
    private static function validatedPng(string $data): string {
        abort_unless(str_starts_with($data,'data:image/png;base64,'),422,'ختم موش لازم يكون صورة PNG.');
        $raw=base64_decode(substr($data,22),true);
        abort_unless($raw && strlen($raw)<=160000 && str_starts_with($raw,"\x89PNG\r\n\x1a\n"),422,'الصورة غير صالحة أو كبيرة.');
        $size=getimagesizefromstring($raw);
        abort_unless($size && $size[0]>0 && $size[1]>0 && $size[0]<=1600 && $size[1]<=1600 && ($size['mime']??'')==='image/png',422,'قياسات ختم موش غير صالحة.');
        return hash('sha256',$raw);
    }
    public function propose(Request $r) {
        $u=$r->user();abort_unless(self::proposer($u),403);
        $v=$r->validate(['image'=>'required|string|max:220000','password'=>'required|string|max:300',
            'reason'=>'required|string|min:3|max:1000','requestId'=>'required|string|min:8|max:128']);
        abort_unless(Hash::check($v['password'],(string)$u->password),403,'كلمة السر غير صحيحة.');
        $fp=self::validatedPng($v['image']);
        $res=R19Store::commit($u,'r24.stationery.propose',['hash'=>$fp,'reason'=>$v['reason'],'requestId'=>$v['requestId']],function($tx)use($u,$v,$fp){
            $id='SEAL24-'.Str::ulid();$d=['id'=>$id,'status'=>'pending','company'=>'MOSH Group',
                'image'=>$v['image'],'fingerprint'=>$fp,'reason'=>trim($v['reason']),
                'proposedBy'=>(string)$u->id,'proposedByName'=>$u->name,'proposedAt'=>now()->toIso8601String()];
            $saved=R19Store::put(self::PENDING.$id,$d,0);
            Audit::record($u,'admin','r24-seal-proposal',$id,'create',null,self::summary($saved),$tx);
            return ['id'=>$id];
        });return $this->ok(self::summary(R19Store::get(self::PENDING.$res['id'])),201);
    }
    public function decide(Request $r,string $id) {
        $u=$r->user();abort_unless(self::approver($u),403);
        $v=$r->validate(['decision'=>'required|in:approve,reject','version'=>'required|integer|min:1',
            'reason'=>'nullable|string|max:1000','requestId'=>'required|string|min:8|max:128']);
        $res=R19Store::commit($u,'r24.stationery.decide.'.$id,$v,function($tx)use($u,$v,$id){
            $key=self::PENDING.$id;$old=R19Store::get($key);
            abort_unless($old&&$old['status']==='pending',409,'الطلب غير متاح أو تمت مراجعته.');
            abort_unless($old['_version']===(int)$v['version'],409,'تغير طلب الختم.');
            $new=$old;$new['status']=$v['decision']==='approve'?'approved':'rejected';
            $new['decidedAt']=now()->toIso8601String();$new['decidedBy']=(string)$u->id;
            $new['decisionReason']=trim($v['reason']??'');
            R19Store::put($key,$new,$old['_version']);
            if($v['decision']==='approve'){
                $last=R19Store::get(self::ACTIVE)??['_version'=>0];
                $current=['id'=>$id,'company'=>'MOSH Group','status'=>'active','fingerprint'=>$old['fingerprint'],
                    'image'=>$old['image'],'approvedAt'=>$new['decidedAt'],'approvedBy'=>(string)$u->id,
                    'history'=>array_merge($last['history']??[], (!empty($last['image'])) ?
                        [['id'=>$last['id'],'fingerprint'=>$last['fingerprint'],'approvedAt'=>$last['approvedAt']??'','retiredAt'=>$new['decidedAt']]]:[])];
                R19Store::put(self::ACTIVE,$current,$last['_version']);
            }
            Audit::record($u,'admin','r24-seal-proposal',$id,$v['decision'],self::summary($old),self::summary($new),$tx);
            return ['id'=>$id];
        });return $this->ok(self::summary(R19Store::get(self::PENDING.$res['id'])));
    }
}

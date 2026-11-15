<?php
namespace App\Services\R29;
use App\Models\{User,Employee,StoredFile,FileLink};
use App\Support\{R19Store,Audit,Money};
use Illuminate\Support\Facades\{Validator,Storage};
use Illuminate\Support\Str;
final class Certificates
{
    public static function issue(User $a,Employee $p,array $in,string $tx): array {
        $v=Validator::make($in,['kind'=>'required|in:experience,service,salary','language'=>'required|in:ar,en','employeeName'=>'required|string|max:200','jobTitle'=>'required|string|max:200',
            'company'=>'required|string|max:200','recipient'=>'nullable|string|max:200','from'=>'required|date_format:Y-m-d|before_or_equal:today',
            'to'=>'nullable|date_format:Y-m-d|after_or_equal:from|before_or_equal:today','salary'=>'nullable|numeric|min:0','currency'=>'nullable|string',
            'signatory'=>'required|string|max:200','signatureKey'=>'required|string|max:190','notes'=>'nullable|string|max:3000'])->validate();
        $seal=\App\Http\Controllers\R24StationeryController::current();abort_unless($seal&&($seal['status']??'')==='active',409,'اعتمد ختم موش الرسمي من الإدارة قبل إصدار شهادة رسمية.');
        $f=StoredFile::where('logical_key',$v['signatureKey'])->firstOrFail();abort_unless($f->owner_id===$a->id&&in_array($f->mime,['image/png','image/jpeg','image/webp'],true)&&$f->size<=1024*1024,422,'ارفع توقيعاً معتمداً كصورة حتى 1 ميغابايت من حسابك.');
        if($v['kind']==='salary'){abort_unless(isset($v['salary'],$v['currency']),422,'أدخل الراتب والعملة.');Money::minor($v['salary'],$v['currency']);}
        $year=substr(HrWorkflow::today(),0,4);$counter=R19Store::get('r29:certificate-counter:'.$year)??['_version'=>0,'last'=>0];$number=(int)$counter['last']+1;
        R19Store::put('r29:certificate-counter:'.$year,['last'=>$number],$counter['_version']);$id=(string)Str::ulid();$token=bin2hex(random_bytes(24));
        $base=rtrim((string)config('app.url'),'/');abort_unless(preg_match('~^https?://[^/]+~',$base)===1,422,'عنوان الخادم APP_URL غير مضبوط.');
        $verify=$base.'/api/hr/certificates/verify/'.$token;QrCode::matrix($verify); // Reject invalid/oversized URL before issuance.
        $c=$v+['id'=>$id,'employeeId'=>(string)$p->id,'number'=>'MOSH-HR-'.$year.'-'.str_pad((string)$number,5,'0',STR_PAD_LEFT),'issuedAt'=>now()->toIso8601String(),
            'issuedBy'=>$a->name,'status'=>'issued','verifyToken'=>$token,'verifyUrl'=>$verify,'sealSnapshot'=>$seal['image'],'sealFingerprint'=>$seal['fingerprint']??''];
        R19Store::put('r29:certificate:'.$id,$c,0);R19Store::put('r29:certificate-token:'.hash('sha256',$token),['certificateId'=>$id],0);
        FileLink::firstOrCreate(['file_id'=>$f->id,'resource'=>'r29-certificate','record_id'=>$id]);Audit::record($a,'hr','r29-certificate',$id,'issue',null,['number'=>$c['number'],'employeeId'=>(string)$p->id],$tx);return ['certificateId'=>$id];
    }
    public static function revoke(User $a,Employee $p,array $in,string $tx): void {
        $v=Validator::make($in,['certificateId'=>'required|string','certificateVersion'=>'required|integer|min:1','reason'=>'required|string|min:3|max:2000'])->validate();
        $c=R19Store::get('r29:certificate:'.$v['certificateId']);abort_unless($c&&$c['employeeId']===(string)$p->id,404);abort_unless($c['_version']===(int)$v['certificateVersion']&&$c['status']==='issued',409);
        $c['status']='revoked';$c['revokedAt']=now()->toIso8601String();$c['revokedBy']=$a->name;$c['reason']=$v['reason'];R19Store::put('r29:certificate:'.$c['id'],$c,$c['_version']);Audit::record($a,'hr','r29-certificate',$c['id'],'revoke',null,['number'=>$c['number'],'reason'=>$v['reason']],$tx);
    }
    public static function verify(string $token): array {
        abort_unless(preg_match('/^[a-f0-9]{48}$/D',$token),404);$v=R19Store::get('r29:certificate-token:'.hash('sha256',$token));abort_unless($v,404);$c=R19Store::get('r29:certificate:'.$v['certificateId']);abort_unless($c,404);
        // Public verification exposes no salary, signatures, employee ID, name or identity documents.
        return ['number'=>$c['number'],'status'=>$c['status'],'issuedAt'=>$c['issuedAt'],'kind'=>$c['kind']];
    }
    public static function html(User $a,string $id): string {
        $c=R19Store::get('r29:certificate:'.$id);abort_unless($c&&($a->active&&(HrWorkflow::hr($a)||(string)$a->employee_id===$c['employeeId'])),404);
        $f=StoredFile::where('logical_key',$c['signatureKey'])->firstOrFail();$raw=Storage::disk('local')->get($f->path);$signature='data:'.$f->mime.';base64,'.base64_encode($raw);
        $e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');$en=$c['language']==='en';$title=($en?['experience'=>'Experience Certificate','service'=>'Certificate of Employment','salary'=>'Salary Certificate']:['experience'=>'شهادة خبرة','service'=>'شهادة خدمة','salary'=>'شهادة راتب'])[$c['kind']];
        $body=$en?'This is to certify that '.$e($c['employeeName']).' has been employed by '.$e($c['company']).' as '.$e($c['jobTitle']).' from '.$e($c['from']).' to '.$e($c['to']?:'the date of issuance').'.':
            'تشهد '.$e($c['company']).' بأن '.$e($c['employeeName']).' يعمل / عمل لديها بمسمى '.$e($c['jobTitle']).' من تاريخ '.$e($c['from']).' وحتى '.$e($c['to']?:'تاريخ إصدار هذه الشهادة').'.';
        if($c['kind']==='salary')$body.='<br><br>'.($en?'The monthly salary is ':'الراتب الشهري هو ').$e($c['salary']).' '.$e($c['currency']).'.';
        $warning=$c['status']==='revoked'?'<aside>'.($en?'REVOKED — NOT VALID':'ملغاة — غير صالحة للاستخدام').'</aside>':'';
        return '<!doctype html><html lang="'.($en?'en':'ar').'" dir="'.($en?'ltr':'rtl').'"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>'.$e($c['number']).'</title><style>body{font:17px/2 Arial,sans-serif;margin:0;color:#19242d;background:#eee}main{max-width:760px;min-height:900px;margin:30px auto;padding:45px;background:white}header{border-bottom:2px solid #967543;display:flex;justify-content:space-between}h1{text-align:center;margin:65px 0 45px}footer{margin-top:75px;display:flex;justify-content:space-between;align-items:end}.sign{max-width:180px;max-height:85px}.seal{max-width:125px;max-height:125px}.qr{width:110px}small{display:block}aside{color:#a00;font-size:26px;text-align:center;border:2px solid #a00}p{white-space:pre-line}@media print{body{background:white}main{margin:0;max-width:none;padding:20mm;min-height:0}@page{size:A4;margin:0}}</style><main>'.$warning.'<header><strong>'.$e($c['company']).'</strong><div>'.$e($c['number']).'<small>'.$e(substr($c['issuedAt'],0,10)).'</small></div></header><h1>'.$title.'</h1><p>'.$e($c['recipient']?:($en?'To whom it may concern':'إلى من يهمه الأمر')).'</p><p>'.$body.'</p><p>'.$e($c['notes']??'').'</p><footer><div>'.$e($c['signatory']).'<br><img class="sign" src="'.$e($signature).'" alt="signature"></div><img class="seal" src="'.$e($c['sealSnapshot']).'" alt="seal"><div class="qr">'.QrCode::svg($c['verifyUrl']).'</div></footer><small>'.($en?'Scan the QR code to verify certificate status.':'امسح رمز QR للتحقق من رقم الشهادة وحالتها.').'</small></main></html>';
    }
}

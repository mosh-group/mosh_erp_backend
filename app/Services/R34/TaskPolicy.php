<?php
namespace App\Services\R34;
use App\Models\User;
/** Guards run on ALL generic task writes, including root and bulk transactions. */
final class TaskPolicy
{
    public static function apply(User $actor, ?array $old, array &$new): void
    {
        $id=(string)($new['id']??'');
        $cancel=CommandContext::permits('cancel-task',$id);
        if (!$cancel) {
            abort_if(($new['cancellation']??null)!==($old['cancellation']??null),403,'مصادقة إلغاء المهمة لا تُعدّل من الحفظ العام.');
            abort_if(($new['status']??'')==='cancelled' && ($old['status']??'')!=='cancelled',403,'إلغاء المهمة يحتاج طلباً ورمز مصادقة المدير التنفيذي.');
            if (($old['status']??'')==='cancelled' && !empty($old['cancellation'])) {
                $a=$old;$b=$new;unset($a['_version'],$b['_version']);
                foreach (['updatedAt','updatedBy'] as $k) {unset($a[$k],$b[$k]);}
                abort_if($a!=$b,409,'المهمة ملغاة ومحفوظة للقراءة فقط.');
            }
        }
        if (!CommandContext::permits('chat',$id)) {
            $known=[];$anonymous=[];foreach($old['workChat']??[]as$m)if(is_array($m)&&empty($m['id']))$anonymous[]=$m;$anonymousUsed=[];
            foreach ($old['workChat']??[] as $m) if (is_array($m)&&!empty($m['id'])) $known[(string)$m['id']]=$m;
            $seen=[];$out=[];
            foreach ($new['workChat']??[] as $m) {
                if(is_array($m)&&empty($m['id'])){$found=false;foreach($anonymous as $ai=>$am)if(!isset($anonymousUsed[$ai])&&$am===$m){$anonymousUsed[$ai]=true;$out[]=$am;$found=true;break;}abort_unless($found,422,'معرّف الرسالة مفقود.');continue;}
                abort_unless(is_array($m)&&is_string($m['id']??null)&&trim($m['id'])!=='',422,'معرّف الرسالة مفقود.');
                $mid=$m['id'];abort_if(isset($seen[$mid]),422,'رسالة مكررة.');$seen[$mid]=true;
                if (isset($known[$mid])) {$out[]=$known[$mid];continue;}
                foreach (['deletedAt','deletedBy','deletedById','deletionReason','editedAt','editedBy','editedById','revisions','revision'] as $k) unset($m[$k]);
                $m['byId']=(string)$actor->id;$m['by']=$actor->name;$m['time']=now()->toIso8601String();$m['createdAt']=$m['time'];
                $out[]=$m;
            }
            abort_unless(count($anonymousUsed)===count($anonymous),409,'لا يمكن إزالة رسالة قديمة من السجل.');
            // Sent messages can only become tombstones; no physical removal via a stale/full-document save.
            foreach ($known as $mid=>$m) abort_unless(isset($seen[$mid]),409,'لا يمكن إخفاء رسالة مرسلة. استخدم حذف الرسالة مع حفظ أثرها.');
            if (array_key_exists('workChat',$new)) $new['workChat']=$out;
            $attachments=array_column($new['attachments']??[],null,'key');
            foreach ($old['attachments']??[] as $a) {
                $key=$a['key']??'';if ($key==='') continue;
                $sent=false;foreach ($old['workChat']??[] as $m) if (($m['attachmentKey']??'')===$key) {$sent=true;break;}
                if ($sent) {abort_unless(isset($attachments[$key]),409,'المرفق مرسل بالمحادثة؛ استخدم حذفاً موثقاً بدلاً من إزالته.');
                    foreach (['deletedAt','deletedBy','deletedById','deletionReason'] as $k) abort_if(($attachments[$key][$k]??null)!==($a[$k]??null),403);}
            }
        }
        Deadline::apply($actor,$old,$new);
    }
}

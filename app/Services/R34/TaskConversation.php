<?php
namespace App\Services\R34;
use App\Models\{Task,User,StoredFile};
use App\Support\{R19Store,Records,RecordAccess};
use Illuminate\Support\Facades\{Validator,Storage};
use Illuminate\Support\Str;
final class TaskConversation
{
    public static function task(User $actor,string $id,bool $write=true): Task
    {
        $t=Task::findOrFail($id);$d=$t->document();$m=RecordAccess::module('tasks',$d);
        abort_unless($actor->active && !$actor->must_change && RecordAccess::readable($actor,'tasks',$d,'details'),403);
        if ($write) {
            abort_if(in_array($d['status']??'',['cancelled','invoiced','archived_invoiced','archived_no_invoice'],true),409,'المهمة مؤرشفة أو ملغاة؛ المحادثة للقراءة فقط.');
            abort_unless(RecordAccess::own($actor,$d)||$actor->manages($d['department']??'')||$actor->allows($m,'edit'),403,'المحادثة في هذه النافذة للاطلاع فقط.');
        }
        return $t;
    }
    public static function canModerate(User $actor,array $task,array $message): bool
    {
        if (!empty($message['system'])) return false;
        $module=RecordAccess::module('tasks',$task);
        if (data_get($actor->data,'moduleOverrides.'.$module.'.edit')===false) return (string)($message['byId']??'')===(string)$actor->id;
        return (string)($message['byId']??'')===(string)$actor->id || $actor->root() ||
            ($actor->manages($task['department']??'')&&$actor->allows($module,'edit'));
    }
    public static function send(User $a,string $id,array $in): array
    {
        Validator::make($in,['text'=>'nullable|string|max:20000','files'=>'array|max:100','files.*'=>'required|string|max:190'])->validate();
        $text=trim((string)($in['text']??''));$keys=array_values(array_unique($in['files']??[]));
        abort_if($text===''&&!$keys,422,'اكتب رسالة أو اختر مرفقاً للإرسال.');
        R19Store::commit($a,'r34:chat:send:'.$id,$in,function($tx)use($a,$id,$text,$keys){
            $t=self::task($a,$id);$d=$t->document();$messages=$d['workChat']??[];$attachments=$d['attachments']??[];
            if ($keys) abort_unless($a->allows(RecordAccess::module('tasks',$d),'attachments'),403,'لا تملك صلاحية إرفاق الملفات.');
            $at=now()->toIso8601String();$meta=['byId'=>(string)$a->id,'by'=>$a->name,'time'=>$at,'createdAt'=>$at];
            if($text!=='')$messages[]=$meta+['id'=>'MSG/'.Str::ulid(),'kind'=>'text','text'=>$text];
            foreach($keys as $key){
                $file=StoredFile::where('logical_key',$key)->first();
                abort_unless($file&&(string)$file->owner_id===(string)$a->id&&Storage::disk('local')->exists($file->path),422,'المرفق غير مكتمل أو ليس مرفوعاً بواسطة حسابك.');
                abort_if(collect($messages)->contains(fn($m)=>($m['attachmentKey']??'')===$key),409,'هذا المرفق مرسل سابقاً؛ حدّث المحادثة.');
                $attachment=['key'=>$key,'name'=>$file->name,'type'=>$file->mime,'size'=>(int)$file->size,'createdAt'=>$at];
                if(!collect($attachments)->contains(fn($x)=>($x['key']??'')===$key))$attachments[]=$attachment;
                $messages[]=$meta+['id'=>'MEDIA/'.Str::ulid(),'kind'=>'media','attachmentKey'=>$key,'mediaType'=>$file->mime,'name'=>$file->name,'size'=>(int)$file->size];
            }
            CommandContext::run('chat',$id,fn()=>Records::change($a,['resource'=>'tasks','action'=>'update','id'=>$id,'version'=>$t->version,'data'=>['workChat'=>$messages,'attachments'=>$attachments]],$tx));
            return ['id'=>$id];
        });
        return self::task($a,$id,false)->document();
    }
    public static function change(User $a,string $id,array $in): array
    {
        Validator::make($in,['messageId'=>'required|string|max:250','action'=>'required|in:delete,edit',
            'text'=>'nullable|string|max:20000','reason'=>'nullable|string|max:2000','messageVersion'=>'nullable|string|max:100','messageRevision'=>'required|integer|min:0'])->validate();
        R19Store::commit($a,'r34:chat:change:'.$id,$in,function($tx)use($a,$id,$in){
            $t=self::task($a,$id);$d=$t->document();$messages=$d['workChat']??[];$index=null;
            foreach($messages as $i=>$m)if(($m['id']??'')===$in['messageId']){$index=$i;break;}
            abort_unless($index!==null,404,'الرسالة غير موجودة.');$m=$messages[$index];
            abort_unless(self::canModerate($a,$d,$m),403,'لا تملك صلاحية تعديل أو حذف هذه الرسالة.');
            abort_if(!empty($m['deletedAt']),409,'الرسالة محذوفة سابقاً.');
            abort_unless((int)$in['messageRevision']===(int)($m['revision']??0)&&($in['messageVersion']??'')===($m['editedAt']??''),409,'تغيرت الرسالة؛ حدّث المحادثة.');
            $at=now()->toIso8601String();$attachments=$d['attachments']??[];
            if($in['action']==='delete'){
                $m+=['deletedAt'=>$at,'deletedBy'=>$a->name,'deletedById'=>(string)$a->id,'deletionReason'=>trim((string)($in['reason']??''))];
                if(!empty($m['attachmentKey']))foreach($attachments as &$att)if(($att['key']??'')===$m['attachmentKey'])foreach(['deletedAt','deletedBy','deletedById','deletionReason']as$k)$att[$k]=$m[$k];unset($att);
            } else {
                abort_if(!empty($m['attachmentKey'])||($m['kind']??'')==='media',422,'بدّل المرفق الخاطئ بحذف موثق وإرسال المرفق الصحيح.');
                $text=trim((string)($in['text']??''));abort_if($text==='',422,'نص التصحيح مطلوب.');
                $revs=$m['revisions']??[];abort_if(count($revs)>=50,422,'بلغت الرسالة حد المراجعات؛ أرسل تصحيحاً برسالة جديدة.');
                $revs[]=['text'=>$m['text']??'','replacedAt'=>$at,'replacedBy'=>$a->name,'replacedById'=>(string)$a->id];
                $m['revisions']=$revs;$m['text']=$text;$m['editedAt']=$at;$m['editedBy']=$a->name;$m['editedById']=(string)$a->id;
            }
            $m['revision']=(int)($m['revision']??0)+1;$messages[$index]=$m;
            CommandContext::run('chat',$id,fn()=>Records::change($a,['resource'=>'tasks','action'=>'update','id'=>$id,'version'=>$t->version,'data'=>['workChat'=>$messages,'attachments'=>$attachments]],$tx));
            return ['id'=>$id];
        });
        return self::task($a,$id,false)->document();
    }
}

<?php
namespace App\Http\Controllers;
use App\Models\{StoredFile,FileLink};
use App\Support\{RecordAccess,Records,Audit,R19HrCompensation};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
class FileController extends Controller
{
    private function find(Request $r,string $key): StoredFile {
        $f = StoredFile::includingHrCompensation()->where('logical_key', $key)->firstOrFail();
        if ($f->kind === R19HrCompensation::FILE_KIND) {
            abort_unless(R19HrCompensation::canReadFile($r->user(), $f), 404);

            return $f;
        }
        $links=FileLink::where('file_id',$f->id)->get();
        if($links->contains(fn($l)=>in_array($l->resource,['employment-contracts','r29-contract-scan'],true))) abort_unless(\App\Services\R29\HrWorkflow::hr($r->user()),404);
        $allowed=$r->user()->root()||($links->isEmpty()&&$f->owner_id===$r->user()->id);
        foreach ($links as $link) {
            if($link->resource==='communication') { if(CommunicationController::fileAllowed($r->user(),(string)$link->record_id))$allowed=true; continue; }
            if(str_starts_with($link->resource,'r31-it-')) { if(\App\Services\R31\ItArchive::canReadFile($r->user(),$link->resource,(string)$link->record_id)) $allowed=true; continue; }
            if(str_starts_with($link->resource,'r29-')) { if(\App\Services\R29\HrWorkflow::canReadLinked($r->user(),$link->resource,(string)$link->record_id)) $allowed=true; continue; }
            if($link->resource==='tasks') { $task=\App\Models\Task::find($link->record_id);if($task&&\App\Services\R29\CustomerWork::canReadTaskFile($r->user(),$task))$allowed=true; }
            if ($link->resource==='r34-facebook-post') { if(\App\Services\Social\FacebookHub::canReadPost($r->user(),(string)$link->record_id)) $allowed=true; continue; }
            if ($link->resource===ManualSocialPostController::ATTACHMENT_RESOURCE) {
                if (ManualSocialPostController::canReadAttachment($r->user(), (string) $link->record_id)) $allowed=true;
                continue;
            }
            if ($link->resource==='r28-social-post') {
                if (\App\Services\Instagram\SocialAccess::canReadPost($r->user(),(string)$link->record_id)) $allowed=true;
                continue;
            }
            if ($link->resource==='r19-disciplinary') {
                if (\App\Support\R19HrWorkflow::canReadDisciplinary($r->user(),(string)$link->record_id)) $allowed=true;
                continue;
            }
            $class=Records::model($link->resource);$row=$class::find($link->record_id);
            if ($row&&empty($row->data['deletedAt'])&&RecordAccess::readable($r->user(),$link->resource,$row->document(),'details')) {
                if ($link->resource==='employees'&&(string)$r->user()->employee_id!==$row->id&&!$r->user()->allows('hr','details')) continue;
                $allowed=true;
            }
        }
        abort_unless($allowed,404);return $f;
    }
    private function metadata(StoredFile $f): array { return ['key'=>$f->logical_key,'name'=>$f->name,'type'=>$f->mime,'mime'=>$f->mime,'size'=>$f->size,'kind'=>$f->kind,'createdAt'=>$f->created_at->toIso8601String()]; }
    public function store(Request $r) {
        $incoming=$r->file('file');
        if ($incoming && !$incoming->isValid()) {
            $code=$incoming->getError();
            $message=match($code){
                UPLOAD_ERR_INI_SIZE,UPLOAD_ERR_FORM_SIZE=>'حد رفع PHP أصغر من المرفق. حدّث الواجهة لاستخدام الرفع المجزأ الآمن.',
                UPLOAD_ERR_PARTIAL=>'وصل جزء فقط من الملف؛ أعد المحاولة.',
                UPLOAD_ERR_NO_TMP_DIR=>'مجلد رفع PHP المؤقت غير متاح. استخدم واجهة R31 للرفع المجزأ أو راجع مسؤول الخادم.',
                UPLOAD_ERR_CANT_WRITE=>'تعذر كتابة الملف المؤقت على الخادم. تحقق من المساحة والصلاحيات.',
                UPLOAD_ERR_EXTENSION=>'إعداد على الخادم أوقف رفع الملف؛ راجع مسؤول الخادم.',
                default=>'لم يصل ملف صالح للرفع.'
            };
            abort(in_array($code,[UPLOAD_ERR_INI_SIZE,UPLOAD_ERR_FORM_SIZE],true)?413:422,$message);
        }
        // R34: no application per-file cap; PHP multipart limits still apply to the legacy path.
        // The standard uploader uses bounded chunks. Disk space is checked before storing.
        if ($r->hasFile('file')) {
            $size=(int)$r->file('file')->getSize();
            $free=@disk_free_space(Storage::disk('local')->path(''));
            abort_if($size<1,422,'الملف فارغ.');
            abort_if($free!==false && ($size>$free || $free-$size<32*1024*1024),507,'مساحة الخادم لا تكفي لحفظ الملف.');
        }
        $v=$r->validate(['file'=>'required|file','key'=>'nullable|string|max:190','kind'=>'nullable|string|max:80']);
        R19HrCompensation::authorizeUpload($r->user(), $v['kind'] ?? null);
        $file=$v['file'];$key=$v['key']??('FILE-'.Str::ulid());
        abort_if(StoredFile::includingHrCompensation()->where('logical_key',$key)->exists(),409,'مفتاح الملف مستخدم.');
        $name=$file->getClientOriginalName();
        $original=method_exists($file,'getClientOriginalPath')?$file->getClientOriginalPath():$name;
        abort_if($original!==$name||preg_match('/[\\\\\/\x00-\x1F\x7F]/u',$name)||in_array($name,['','.','..'],true)||mb_strlen($name)>250,422,'اسم ملف غير آمن.');
        abort_if(preg_match('/\.(php[0-9]*|phtml|phar|cgi|pl|py|rb|sh|bash|zsh|fish|ps1|vbs|vbe|wsf|wsh|hta|asp|aspx|jsp|exe|com|bat|cmd|msi|msp|scr|dll|jar|js|mjs|cjs|html?|svg)(\.|$)/i',$name),422,'امتداد الملف غير مسموح.');
        $extension=strtolower(pathinfo($name,PATHINFO_EXTENSION));
        $allowedExtensions=['jpg','jpeg','png','webp','gif','heic','heif','bmp','tif','tiff',
            'mp4','mov','m4v','webm','avi','mkv','mpeg','mpg','3gp',
            'mp3','wav','m4a','aac','ogg','flac',
            'pdf','txt','csv','rtf','doc','docx','xls','xlsx','ppt','pptx',
            'zip','rar','7z','psd','ai','eps','indd','dwg','dxf'];
        abort_unless(in_array($extension,$allowedExtensions,true),422,'نوع الملف غير مسموح كمرفق.');
        $mime=$file->getMimeType() ?: 'application/octet-stream';
        $id=(string)Str::ulid();$path=$file->storeAs('attachments/'.$r->user()->id,$id,'local');
        abort_unless($path,503,'تعذر حفظ الملف.');
        try { $f=StoredFile::create(['id'=>$id,'owner_id'=>$r->user()->id,'logical_key'=>$key,'name'=>$name,'mime'=>$mime,'kind'=>$v['kind']??'attachment','size'=>$file->getSize(),'path'=>$path]); }
        catch (\Throwable $e) { Storage::disk('local')->delete($path);throw $e; }
        return $this->ok($this->metadata($f),201);
    }
    public function show(Request $r, string $key)
    {
        $file = $this->find($r, $key);
        $response = $this->ok($this->metadata($file));

        return $file->kind === R19HrCompensation::FILE_KIND ? $response->header('Cache-Control', 'private, no-store') : $response;
    }
    public function content(Request $r,string $key) {
        $f=$this->find($r,$key);abort_unless(Storage::disk('local')->exists($f->path),404);
        return Storage::disk('local')->response($f->path,$f->name,['Content-Type'=>$f->mime,'X-Content-Type-Options'=>'nosniff','Cache-Control'=>'private, no-store'],!$r->boolean('download')&&in_array($f->mime,['image/png','image/jpeg','image/webp','image/gif','application/pdf'],true)?'inline':'attachment');
    }
    public function destroy(Request $r,string $key) {
        $path=\Illuminate\Support\Facades\DB::transaction(function()use($r,$key){
            // Share the record-write lock so canceling an upload cannot delete
            // a file while a task or HR request is committing its file link.
            \Illuminate\Support\Facades\DB::table('app_locks')->where('id','business')->increment('revision');
            $visible = $this->find($r, $key);
            R19HrCompensation::authorizeUpload($r->user(), $visible->kind);
            $f = StoredFile::includingHrCompensation()->whereKey($visible->id)->lockForUpdate()->firstOrFail();
            abort_unless($f->owner_id===$r->user()->id||$r->user()->root(),403);
            abort_if(FileLink::where('file_id',$f->id)->exists(),409,'أزل المرفق من السجل أولاً.');
            $path=$f->path;$f->delete();return $path;
        },3);
        Storage::disk('local')->delete($path);return $this->ok(null);
    }
}

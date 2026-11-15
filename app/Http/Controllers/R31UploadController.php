<?php
namespace App\Http\Controllers;
use App\Models\StoredFile;
use App\Services\R31\ChunkStorage;
use Illuminate\Http\{Request,UploadedFile};
use Illuminate\Support\Facades\Storage;

final class R31UploadController extends Controller
{
    private function store(): ChunkStorage {return new ChunkStorage(Storage::disk('local')->path('r31-uploads'));}
    private function run(callable $fn) {try{return $this->ok($fn());}catch(\DomainException $e){abort(422,$e->getMessage());}catch(\RuntimeException $e){if($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface)throw $e;abort(503,'تعذر حفظ المرفق على الخادم؛ راجع مساحة التخزين وصلاحيات مجلد المرفقات.');}}
    public function limits(Request $r){return $this->ok(['maxBytes'=>null,'storageBound'=>true,'chunkBytes'=>ChunkStorage::PART_BYTES,'resumable'=>true,'phpUploadMax'=>ini_get('upload_max_filesize'),'phpPostMax'=>ini_get('post_max_size')]);}
    public function begin(Request $r){$v=$r->validate(['key'=>'required|string|max:190','name'=>'required|string|max:250','size'=>'required|integer|min:1','sha256'=>'required|regex:/^[a-f0-9]{64}$/D','kind'=>'nullable|string|max:80']);return $this->run(fn()=>$this->store()->begin((string)$r->user()->id,$v));}
    public function part(Request $r,string $id,string $part){$v=$r->validate(['base64'=>'required|string|max:349528']);return $this->run(fn()=>$this->store()->part((string)$r->user()->id,$id,(int)$part,$v['base64']));}
    public function complete(Request $r,string $id){return $this->run(fn()=>$this->store()->complete((string)$r->user()->id,$id,function($path,$m)use($r){
        // Serialize against normal writes and file deletion, including lost-response retries.
        return \Illuminate\Support\Facades\DB::transaction(function()use($path,$m,$r){
            abort_unless(\Illuminate\Support\Facades\DB::table('app_locks')->where('id','business')->increment('revision')===1,503);
            $existing=StoredFile::where('logical_key',$m['key'])->first();
            if($existing){abort_unless($existing->owner_id===$r->user()->id&&$existing->name===$m['name']&&(int)$existing->size===$m['size']&&Storage::disk('local')->exists($existing->path)&&hash_equals($m['sha256'],hash_file('sha256',Storage::disk('local')->path($existing->path))),409,'مفتاح المرفق مستخدم لملف آخر.');return $existing->document();}
            $req=Request::create('/api/files','POST',['key'=>$m['key'],'kind'=>$m['kind']]);$req->setUserResolver(fn()=>$r->user());$req->files->set('file',new UploadedFile($path,$m['name'],null,UPLOAD_ERR_OK,true));
            $response=app(FileController::class)->store($req);return $response->getData(true)['data'];
        },3);
    }));}
    public function cancel(Request $r,string $id){return $this->run(function()use($r,$id){$this->store()->cancel((string)$r->user()->id,$id);return null;});}
}

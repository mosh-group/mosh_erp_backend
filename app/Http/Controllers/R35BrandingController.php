<?php
namespace App\Http\Controllers;
use App\Models\Setting;
use App\Support\{Audit,R35Branding};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Storage};
final class R35BrandingController extends Controller
{
    private const KEY='r35-branding';
    private function publicValue(?Setting $row): array {
        $v=$row?->value??[];$data=null;$path=(string)($v['path']??'');
        if(R35Branding::safePath($path)&&Storage::disk('local')->exists($path)) {
            $data='data:'.($v['mime']??'image/png').';base64,'.base64_encode(Storage::disk('local')->get($path));
        }
        return ['mode'=>($v['mode']??'text')==='image'&&$data?'image':'text','image'=>$data,'_version'=>$row?->version??0];
    }
    public function show(Request $r) {
        abort_unless($r->user()?->active,403);
        return $this->ok($this->publicValue(Setting::find(self::KEY)));
    }
    public function update(Request $r) {
        abort_unless(R35Branding::canManage($r->user()),403,'لا تملك صلاحية تعديل شعار النظام.');
        $v=$r->validate(['mode'=>'required|in:text,image','image'=>'nullable|string|max:2796244','remove'=>'sometimes|boolean','_version'=>'required|integer|min:0']);
        $file=null;
        if(!empty($v['image']))try{$file=R35Branding::decode($v['image']);}catch(\InvalidArgumentException $e){abort(422,$e->getMessage());}
        // Brand files are immutable by content hash; rollback cannot delete another user's logo.
        // Old logos are retained, never used as a route to company attachments.
        $row=DB::transaction(function()use($r,$v,$file){
            DB::table('app_locks')->where('id','business')->increment('revision');
            $row=Setting::whereKey(self::KEY)->lockForUpdate()->first();$before=$row?->value??[];
            $next=$before;$next['mode']=$v['mode'];
            if($v['remove']??false){unset($next['path'],$next['mime'],$next['hash']);$next['mode']='text';}
            if($file){$next['path']=$file['path'];$next['mime']=$file['mime'];$next['hash']=$file['hash'];}
            abort_if($next['mode']==='image'&&empty($next['path']),422,'اختر صورة شعار أولاً.');
            if($row&&$before===$next)return $row; // Same completed save is safe to retry.
            abort_unless((int)$v['_version']===(int)($row?->version??0),409,'تغير الشعار بواسطة مستخدم آخر. أعد فتح الإعدادات قبل الحفظ.');
            if($file)abort_unless(Storage::disk('local')->put($file['path'],$file['bytes']),503,'تعذر حفظ الشعار على الخادم.');
            $version=(int)($row?->version??0)+1;
            if($row)$row->update(['value'=>$next,'version'=>$version]);
            else $row=Setting::create(['id'=>self::KEY,'value'=>$next,'version'=>$version]);
            Audit::record($r->user(),'admin','ui-branding',self::KEY,'update',array_diff_key($before,['image'=>true]),array_diff_key($next,['image'=>true]),null,'تغيير شعار النظام');
            return $row;
        },3);
        return $this->ok($this->publicValue($row));
    }
}

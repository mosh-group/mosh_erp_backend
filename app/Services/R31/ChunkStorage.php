<?php
namespace App\Services\R31;

/** Private resumable uploads, independent of PHP multipart temporary storage.
 * All limits are server enforced. Caller authenticates before every operation.
 * No public paths or original filenames are used for filesystem operations. */
final class ChunkStorage
{
    // Representational limit only; there is no configured per-file business ceiling.
    public const MAX_BYTES = PHP_INT_MAX;
    public const PART_BYTES = 256 * 1024;
    private const TTL = 86400;
    public function __construct(private string $root) {}
    private function folder(string $owner): string {
        $p=$this->root.'/'.hash('sha256',$owner);
        if(!is_dir($p)&&!@mkdir($p,0700,true)&&!is_dir($p)) throw new \RuntimeException('مجلد رفع الملفات غير قابل للكتابة؛ راجع مسؤول الخادم.');
        return $p;
    }
    public static function validateName(string $name): void {
        if($name===''||strlen($name)>750||preg_match('/[\\\\\/\x00-\x1F\x7F]/u',$name)||in_array($name,['.','..'],true)) throw new \DomainException('اسم ملف غير آمن.');
        if(preg_match('/\.(php[0-9]*|phtml|phar|cgi|pl|py|rb|sh|bash|zsh|fish|ps1|vbs|vbe|wsf|wsh|hta|asp|aspx|jsp|exe|com|bat|cmd|msi|msp|scr|dll|jar|js|mjs|cjs|html?|svg)(\.|$)/i',$name)) throw new \DomainException('امتداد الملف غير مسموح.');
        $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
        if(!in_array($ext,['jpg','jpeg','png','webp','gif','heic','heif','bmp','tif','tiff','mp4','mov','m4v','webm','avi','mkv','mpeg','mpg','3gp','mp3','wav','m4a','aac','ogg','flac','pdf','txt','csv','rtf','doc','docx','xls','xlsx','ppt','pptx','zip','rar','7z','psd','ai','eps','indd','dwg','dxf'],true)) throw new \DomainException('نوع الملف غير مسموح كمرفق.');
    }
    private function space(string $path, int $bytes): void {
        $free = @disk_free_space(is_dir($path) ? $path : dirname($path));
        $reserve = 32 * 1024 * 1024;
        if ($free !== false && ($bytes > $free || $free - $bytes < $reserve))
            throw new \DomainException('مساحة التخزين المتاحة على الخادم لا تكفي لهذه العملية. لم يُحذف أي ملف محفوظ.');
    }
    private function atomic(string $path,string $bytes): void {
        $this->space($path, strlen($bytes));
        $tmp=$path.'.'.bin2hex(random_bytes(8)).'.tmp';
        try { if(@file_put_contents($tmp,$bytes,LOCK_EX)!==strlen($bytes)||!@rename($tmp,$path)) throw new \RuntimeException('تعذر الكتابة في تخزين المرفقات؛ تحقق من المساحة والصلاحيات.');@chmod($path,0600); }
        finally {if(is_file($tmp))@unlink($tmp);}
    }
    private function locked(string $owner,string $id,callable $fn,bool $create=false): mixed {
        if(!preg_match('/^[a-f0-9]{64}$/D',$id)) throw new \DomainException('معرف رفع غير صالح.');
        $dir=$this->folder($owner).'/'.$id;
        if(!is_dir($dir)&&!$create)throw new \DomainException('جلسة رفع غير موجودة.');
        if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir)) throw new \RuntimeException('تعذر إنشاء مساحة رفع الملف.');
        $lock=@fopen($dir.'/lock','c');if(!$lock)throw new \RuntimeException('تعذر قفل الملف.');
        try {if(!flock($lock,LOCK_EX))throw new \RuntimeException('تعذر قفل الملف.');return $fn($dir);}
        finally {flock($lock,LOCK_UN);fclose($lock);}
    }
    private function load(string $dir): array {
        if(!is_file($dir.'/meta.json'))throw new \DomainException('جلسة رفع غير موجودة أو انتهت؛ أعد اختيار الملف.');
        $m=json_decode(file_get_contents($dir.'/meta.json'),true,32,JSON_THROW_ON_ERROR);
        if(($m['expiresAt']??0)<time())throw new \DomainException('انتهت جلسة رفع الملف؛ أعد اختياره.');return $m;
    }
    private function view(array $m): array {return ['id'=>$m['id'],'chunkSize'=>self::PART_BYTES,'parts'=>$m['parts'],'received'=>array_map('intval',array_keys($m['received']??[])),'complete'=>isset($m['result'])];}
    public function begin(string $owner,array $input): array {
        self::validateName((string)($input['name']??''));$size=$input['size']??0;
        if(!is_int($size)||$size<1)throw new \DomainException('حجم الملف غير صالح أو الملف فارغ.');
        if(PHP_INT_SIZE<8 && $size>2147483647)throw new \DomainException('بيئة PHP الحالية 32 بت؛ الملفات الكبيرة تحتاج بيئة 64 بت.');
        $key=(string)($input['key']??'');if($key===''||strlen($key)>190)throw new \DomainException('مفتاح الملف غير صالح.');
        if(!preg_match('/^[a-f0-9]{64}$/D',(string)($input['sha256']??'')))throw new \DomainException('بصمة الملف غير صالحة.');
        $kind=(string)($input['kind']??'attachment');if(strlen($kind)>80)throw new \DomainException('نوع المرفق غير صالح.');
        $spec=['key'=>$key,'name'=>$input['name'],'size'=>$size,'sha256'=>$input['sha256'],'kind'=>$kind];
        $id=hash('sha256',$owner.'|'.$key);$root=$this->folder($owner);$this->prune($root);
        $lock=fopen($root.'/quota.lock','c');if(!$lock)throw new \RuntimeException('تعذر فتح مساحة الرفع.');
        try {flock($lock,LOCK_EX);
            return $this->locked($owner,$id,function($dir)use($id,$spec,$root){
                if(is_file($dir.'/meta.json')){$m=$this->load($dir);foreach($spec as $k=>$v)if(($m[$k]??null)!==$v)throw new \DomainException('مفتاح الرفع مستخدم لملف مختلف.');return $this->view($m);}
                $open=0;foreach(glob($root.'/*/meta.json')?:[] as $f){$x=json_decode(file_get_contents($f),true);if(empty($x['result'])&&($x['expiresAt']??0)>time())$open++;}
                if($open>=12)throw new \DomainException('لديك 12 عملية رفع غير مكتملة؛ أكملها أو ألغها قبل إضافة ملفات.');
                $this->space($dir, $spec['size']);
                $m=$spec+['id'=>$id,'parts'=>(int)ceil($spec['size']/self::PART_BYTES),'received'=>[],'expiresAt'=>time()+self::TTL];
                $this->atomic($dir.'/meta.json',json_encode($m,JSON_THROW_ON_ERROR));return $this->view($m);
            },true);
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    public function part(string $owner,string $id,int $index,string $base64): array {
        if(strlen($base64)>4*(int)ceil(self::PART_BYTES/3))throw new \DomainException('جزء الرفع أكبر من المسموح.');
        $bytes=base64_decode($base64,true);if($bytes===false)throw new \DomainException('جزء الملف تالف.');
        return $this->locked($owner,$id,function($dir)use($index,$bytes){
            $m=$this->load($dir);if($index<0||$index>=$m['parts'])throw new \DomainException('رقم جزء غير صالح.');
            $expected=min(self::PART_BYTES,$m['size']-$index*self::PART_BYTES);if(strlen($bytes)!==$expected)throw new \DomainException('حجم جزء الملف غير مطابق.');
            $hash=hash('sha256',$bytes);
            if(isset($m['received'][$index])) {if(!hash_equals($m['received'][$index],$hash))throw new \DomainException('الجزء المكرر مختلف؛ لم يُستبدل الجزء المحفوظ.');return ['index'=>$index,'received'=>count($m['received'])];}
            if(isset($m['result']))throw new \DomainException('الرفع مكتمل.');
            $this->atomic($dir.'/'.$index.'.part',$bytes);$m['received'][$index]=$hash;
            $m['expiresAt']=time()+self::TTL;
            $this->atomic($dir.'/meta.json',json_encode($m,JSON_THROW_ON_ERROR));return ['index'=>$index,'received'=>count($m['received'])];
        });
    }
    /** Finalizer validates/stores the file through the normal private attachment service. */
    public function complete(string $owner,string $id,callable $finalizer,?callable $authorize=null): array {
        return $this->locked($owner,$id,function($dir)use($finalizer,$authorize){
            $m=$this->load($dir);
            if($authorize) $authorize($m);
            if(isset($m['result']))return $m['result'];
            if(count($m['received'])!==$m['parts'])throw new \DomainException('لم تصل كل أجزاء الملف؛ أعد المحاولة لاستكمالها.');
            $this->space($dir,$m['size']);
            if(function_exists('set_time_limit')) @set_time_limit(0);
            $path=$dir.'/assembled';$out=fopen($path,'wb');if(!$out)throw new \RuntimeException('تعذر تجميع المرفق.');
            try{for($i=0;$i<$m['parts'];$i++){$in=fopen($dir.'/'.$i.'.part','rb');if(!$in)throw new \RuntimeException('جزء من الملف مفقود.');try{$n=stream_copy_to_stream($in,$out);if($n!==min(self::PART_BYTES,$m['size']-$i*self::PART_BYTES))throw new \RuntimeException('تعذر تجميع المرفق بالكامل.');}finally{fclose($in);}}}finally{fclose($out);}
            try {if(filesize($path)!==$m['size']||!hash_equals($m['sha256'],hash_file('sha256',$path)))throw new \DomainException('بصمة الملف غير مطابقة؛ لم يتم حفظ المرفق.');
                $result=$finalizer($path,$m);$m['result']=$result;$this->atomic($dir.'/meta.json',json_encode($m,JSON_THROW_ON_ERROR));
                foreach(glob($dir.'/*.part')?:[] as $f)@unlink($f);return $result;
            }finally{if(is_file($path))@unlink($path);}
        });
    }
    public function cancel(string $owner,string $id): void {$this->locked($owner,$id,function($dir){$m=$this->load($dir);if(isset($m['result']))return;foreach(glob($dir.'/*.part')?:[] as $f)@unlink($f);@unlink($dir.'/meta.json');});}
    private function prune(string $root): void {foreach(glob($root.'/*/meta.json')?:[] as $f){$m=json_decode(@file_get_contents($f)?:'{}',true);if(($m['expiresAt']??PHP_INT_MAX)>=time())continue;$d=dirname($f);$l=@fopen($d.'/lock','c');if(!$l)continue;if(flock($l,LOCK_EX|LOCK_NB)){foreach(glob($d.'/*')?:[] as $p)if(is_file($p)&&basename($p)!=='lock')@unlink($p);flock($l,LOCK_UN);}fclose($l);}}
}

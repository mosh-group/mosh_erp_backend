<?php
namespace App\Support;
/** Small branding images only. Does not change the unrestricted task attachment flow. */
final class R35Branding
{
    public const MAX_BYTES = 2097152;
    public static function canManage(?object $user): bool {
        return ($user?->active ?? false) && ($user->root() || $user->globalPermission('systemCustomization') || $user->globalPermission('appearanceSettings'));
    }
    public static function decode(string $data): array {
        if (strlen($data)>4*ceil(self::MAX_BYTES/3)+40 || !preg_match('~\Adata:(image/(?:png|jpeg|webp));base64,([A-Za-z0-9+/]+={0,2})\z~D',$data,$m)) {
            throw new \InvalidArgumentException('اختر شعاراً بصيغة PNG أو JPEG أو WebP وبحجم لا يتجاوز 2 ميغابايت.');
        }
        $bytes=base64_decode($m[2],true);
        if($bytes===false||strlen($bytes)<12||strlen($bytes)>self::MAX_BYTES||base64_encode($bytes)!==$m[2])throw new \InvalidArgumentException('ملف الشعار غير صالح.');
        $info=@getimagesizefromstring($bytes);
        if(!$info||!in_array($info['mime']??'', ['image/png','image/jpeg','image/webp'],true)||$info['mime']!==$m[1]||$info[0]<1||$info[1]<1||$info[0]>4096||$info[1]>4096) {
            throw new \InvalidArgumentException('الصورة غير صالحة أو أبعادها أكبر من 4096 بكسل.');
        }
        $ext=['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'][$m[1]];
        return ['bytes'=>$bytes,'mime'=>$m[1],'width'=>$info[0],'height'=>$info[1],'hash'=>hash('sha256',$bytes),'path'=>'r35-branding/'.hash('sha256',$bytes).'.'.$ext];
    }
    public static function safePath(string $path): bool { return (bool)preg_match('~\Ar35-branding/[a-f0-9]{64}\.(?:png|jpg|webp)\z~D',$path); }
}

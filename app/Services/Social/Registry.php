<?php
namespace App\Services\Social;
final class Registry
{
    public static function platforms(): array
    {
        return [['id'=>'instagram','name'=>'Instagram','enabled'=>true,'kinds'=>['image','reel'],'implementation'=>'instagram-login'],
            ['id'=>'facebook','name'=>'Facebook Pages','enabled'=>true,'kinds'=>['text','image','video'],'implementation'=>'facebook-pages'],
            ['id'=>'tiktok','name'=>'TikTok','enabled'=>false,'kinds'=>[],'implementation'=>'planned'],
            ['id'=>'linkedin','name'=>'LinkedIn','enabled'=>false,'kinds'=>[],'implementation'=>'planned']];
    }
    public static function publisher(string $platform): Publisher
    {
        if($platform!=='facebook')throw new \DomainException('This publisher is not installed. Instagram retains its existing adapter.');
        return app(FacebookProvider::class);
    }
}

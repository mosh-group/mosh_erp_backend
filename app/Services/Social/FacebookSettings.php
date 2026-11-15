<?php
namespace App\Services\Social;
use App\Models\User;
use App\Support\{R19Store,Audit};
use App\Services\Instagram\SocialPolicy;
use Illuminate\Support\Facades\Crypt;
final class FacebookSettings
{
    public const KEY='r34:facebook:config';public const WORKER='r34:facebook:worker';
    public static function get(): array
    {
        $s=R19Store::get(self::KEY)??[];
        return ['appId'=>(string)(config('mosh_facebook.app_id')?:($s['appId']??'')),
            'appSecret'=>(string)(config('mosh_facebook.app_secret')?:(!empty($s['encryptedSecret'])?Crypt::decryptString($s['encryptedSecret']):'')),
            'redirectUri'=>config('mosh_facebook.redirect_uri')?:($s['redirectUri']??''),'publicBaseUrl'=>rtrim(config('mosh_facebook.public_base_url')?:($s['publicBaseUrl']??''),'/'),
            'graphVersion'=>$s['graphVersion']??config('mosh_facebook.graph_version','v26.0'),'_version'=>$s['_version']??0];
    }
    public static function configured(array $c): bool {return preg_match('/^\d+$/D',$c['appId'])&&$c['appSecret']!==''&&SocialPolicy::httpsUrl($c['redirectUri'])&&SocialPolicy::httpsUrl($c['publicBaseUrl']);}
    public static function public(bool $technical=false): array
    {
        $c=self::get();$w=R19Store::get(self::WORKER)??[];$out=['configured'=>(bool)self::configured($c),'scheduler'=>['active'=>strtotime($w['lastRunAt']??'')>time()-150,'lastRunAt'=>$w['lastRunAt']??null]];
        if($technical)$out+=array_diff_key($c,['appSecret'=>true])+['hasSecret'=>$c['appSecret']!==''];return$out;
    }
    public static function save(User$a,array$in): array
    {
        abort_unless($a->root(),403);$safe=$in;$safe['appSecretHash']=hash('sha256',$in['appSecret']??'');unset($safe['appSecret']);
        R19Store::commit($a,'facebook:configuration',$safe,function($tx)use($a,$in){abort_unless($a->root(),403);
            abort_unless(SocialPolicy::httpsUrl($in['redirectUri'])&&SocialPolicy::httpsUrl($in['publicBaseUrl'])&&parse_url($in['redirectUri'],PHP_URL_PATH)==='/api/social/facebook/callback',422,'يلزم HTTPS وعنوان رجوع ينتهي بـ /api/social/facebook/callback');
            $old=R19Store::get(self::KEY)??[];$d=array_intersect_key($in,array_flip(['appId','redirectUri','publicBaseUrl','graphVersion']));
            $d['encryptedSecret']=!empty($in['appSecret'])?Crypt::encryptString($in['appSecret']):($old['encryptedSecret']??'');
            abort_unless($d['encryptedSecret']!==''||config('mosh_facebook.app_secret'),422,'سر تطبيق Facebook مطلوب.');
            R19Store::put(self::KEY,$d,(int)$in['_version']);Audit::record($a,'marketing','facebook-configuration','config','update',null,['configured'=>true],$tx);return['saved'=>true];
        });return self::public(true);
    }
}

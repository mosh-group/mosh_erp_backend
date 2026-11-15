<?php
namespace App\Services\Instagram;
use App\Models\User;
use App\Support\{R19Store, Audit};
use Illuminate\Support\Facades\Crypt;
final class SocialSettings
{
    public const KEY = 'r28:social:configuration';
    public static function privateConfig(): array
    {
        $s = R19Store::get(self::KEY) ?? [];
        $secret = config('mosh_social.app_secret') ?: (!empty($s['encryptedSecret']) ? Crypt::decryptString($s['encryptedSecret']) : '');
        return ['appId'=>config('mosh_social.app_id') ?: ($s['appId'] ?? ''), 'appSecret'=>$secret,
            'redirectUri'=>config('mosh_social.redirect_uri') ?: ($s['redirectUri'] ?? ''),
            'publicBaseUrl'=>rtrim(config('mosh_social.public_base_url') ?: ($s['publicBaseUrl'] ?? ''), '/'),
            'graphVersion'=>($s['graphVersion'] ?? '') ?: config('mosh_social.graph_version','v26.0'), '_version'=>$s['_version'] ?? 0];
    }
    public static function configured(array $c): bool
    {
        return preg_match('/^\d+$/D', $c['appId']) && $c['appSecret'] !== '' && SocialPolicy::httpsUrl($c['redirectUri']) && SocialPolicy::httpsUrl($c['publicBaseUrl']);
    }
    public static function publicConfig(bool $technical = false): array
    {
        $c = self::privateConfig(); $out = ['configured'=>(bool)self::configured($c), 'scheduler'=>self::scheduler()];
        if ($technical) $out += ['appId'=>$c['appId'], 'redirectUri'=>$c['redirectUri'], 'publicBaseUrl'=>$c['publicBaseUrl'],
            'graphVersion'=>$c['graphVersion'], 'hasSecret'=>$c['appSecret'] !== '', '_version'=>$c['_version']];
        return $out;
    }
    public static function scheduler(): array
    {
        $s = R19Store::get('r28:social:worker') ?? [];
        return ['active'=>!empty($s['lastRunAt']) && strtotime($s['lastRunAt']) >= time()-150, 'lastRunAt'=>$s['lastRunAt'] ?? null];
    }
    public static function save(User $actor, array $input): array
    {
        R19Store::commit($actor, 'social-config', $input, function ($tx) use ($actor,$input) {
            abort_unless($actor->root(),403);
            abort_unless(SocialPolicy::httpsUrl($input['redirectUri']) && SocialPolicy::httpsUrl($input['publicBaseUrl']),422,'يلزم HTTPS لعنوان الرجوع ورابط الوسائط.');
            abort_unless(parse_url($input['redirectUri'],PHP_URL_PATH) === '/api/social/instagram/callback',422,'عنوان الرجوع يجب أن ينتهي بـ /api/social/instagram/callback');
            $old = R19Store::get(self::KEY) ?? []; $new = array_intersect_key($input,array_flip(['appId','redirectUri','publicBaseUrl','graphVersion']));
            $new['encryptedSecret'] = !empty($input['appSecret']) ? Crypt::encryptString($input['appSecret']) : ($old['encryptedSecret'] ?? '');
            abort_unless($new['encryptedSecret'] !== '' || config('mosh_social.app_secret'),422,'أدخل سر تطبيق Instagram.');
            R19Store::put(self::KEY,$new,(int)$input['_version']);
            Audit::record($actor,'marketing','instagram-config','configuration','update',null,['configured'=>true,'graphVersion'=>$new['graphVersion']],$tx);
            return ['saved'=>true];
        });
        abort_unless($actor->active && !$actor->must_change && $actor->root(),403);
        return self::publicConfig(true);
    }
}

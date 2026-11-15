<?php
namespace App\Services\Instagram;
use Illuminate\Support\Facades\Http;

/** Official Instagram Login API. Only fixed provider hosts can receive tokens.
 * No automatic POST retry: publishing may have succeeded despite a lost response. */
class MetaClient
{
    public function call(string $method, string $url, array $data = [], string $token = ''): array
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || !in_array($parts['host'] ?? '', ['graph.instagram.com','api.instagram.com'], true)) throw new \LogicException('Untrusted Meta endpoint.');
        try {
            $http = Http::acceptJson()->connectTimeout(8)->timeout(25)->withOptions(['allow_redirects' => false]);
            if ($token !== '') $http = $http->withToken($token);
            $response = $method === 'GET' ? $http->get($url, $data) : $http->asForm()->send($method, $url, ['form_params' => $data]);
        } catch (\Throwable $e) { throw new MetaException('network'); }
        $json = $response->json();
        if (!$response->successful() || !is_array($json) || isset($json['error'])) {
            $code = (int)($json['error']['code'] ?? 0);
            $kind = $response->status() === 429 || in_array($code, [4,17,32,613], true) ? 'rate' :
                ($response->status() === 401 || in_array($code, [102,190], true) ? 'auth' :
                ($response->status() === 403 || in_array($code, [10,200], true) ? 'permission' : 'provider'));
            throw new MetaException($kind, $code);
        }
        return $json;
    }
    public function exchange(string $code, array $settings): array
    {
        $short = $this->call('POST', 'https://api.instagram.com/oauth/access_token', [
            'client_id'=>$settings['appId'], 'client_secret'=>$settings['appSecret'], 'grant_type'=>'authorization_code',
            'redirect_uri'=>$settings['redirectUri'], 'code'=>$code]);
        $short = $short['data'][0] ?? $short;
        if (!is_string($short['access_token'] ?? null) || $short['access_token'] === '') throw new MetaException('provider');
        $long = $this->call('GET', 'https://graph.instagram.com/access_token', [
            'grant_type'=>'ig_exchange_token', 'client_secret'=>$settings['appSecret'], 'access_token'=>$short['access_token']]);
        if (empty($long['access_token']) || (int)($long['expires_in'] ?? 0) < 1) throw new MetaException('provider');
        $profile = $this->graph('GET', 'me', ['fields'=>'user_id,username'], $long['access_token'], $settings['graphVersion']);
        $id = (string)($profile['user_id'] ?? $profile['id'] ?? $short['user_id'] ?? '');
        if (!preg_match('/^\d{1,40}$/D', $id) || empty($profile['username'])) throw new MetaException('provider');
        // Instagram Login is for professional accounts. Do not invent a Business/Creator subtype.
        // The minimal identity endpoint does not guarantee account_type in its response.
        return ['instagramId'=>$id, 'username'=>(string)$profile['username'], 'accountType'=>'PROFESSIONAL',
            'token'=>$long['access_token'], 'expiresIn'=>(int)$long['expires_in'], 'permissions'=>$short['permissions'] ?? []];
    }
    public function graph(string $method, string $path, array $data, string $token, string $version): array
    {
        if (!preg_match('/^v\d{1,2}\.0$/D', $version) || !preg_match('~^(me|[0-9]{1,40})(/(media|media_publish|insights|permissions))?$~D', $path)) throw new \LogicException('Invalid Graph path.');
        return $this->call($method, 'https://graph.instagram.com/'.$version.'/'.$path, $data, $token);
    }
    public function refresh(string $token): array
    {
        return $this->call('GET', 'https://graph.instagram.com/refresh_access_token', ['grant_type'=>'ig_refresh_token', 'access_token'=>$token]);
    }
}

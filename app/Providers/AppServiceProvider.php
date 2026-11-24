<?php
namespace App\Providers;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\Response;
class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}
    public function boot(): void
    {
        RateLimiter::for('auth-login', function (Request $request): array {
            $input = $request->input('username');
            $username = is_string($input) ? mb_strtolower(trim(mb_substr($input, 0, 80))) : '';
            $ip = $request->ip();

            return [
                Limit::perMinute(120)->by('network:'.$ip),
                Limit::perMinute(20)->by('account:'.$ip.':'.hash('sha256', $username))
                    ->after(fn (Response $response) => in_array($response->getStatusCode(), [401, 422], true)),
            ];
        });
    }
}

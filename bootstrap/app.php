<?php
use App\Http\Middleware\ApiSession;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php', health: '/status')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trimStrings(except: ['secret.password','secret.recoveryCodes','secret.twoFactorNotes']);
        $middleware->alias(['api.session'=>ApiSession::class,'zkteco.bridge'=>\App\Http\Middleware\ZktecoBridgeAuth::class]);
        $middleware->convertEmptyStringsToNull(except: [fn (Request $request) => $request->is('api/*')]);
        $middleware->redirectGuestsTo(fn (Request $request) => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['secret','secret.password','secret.recoveryCodes','secret.twoFactorNotes','current_password','code','appSecret','access_token','refresh_token','signed_request']);
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
        $exceptions->render(function (Throwable $e, Request $request) {
            if (!$request->is('api/*')) return null;
            $status=$e instanceof ValidationException ? 422 : ($e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500);
            if ($e instanceof Illuminate\Auth\AuthenticationException) $status=401;
            if ($e instanceof Illuminate\Auth\Access\AuthorizationException) $status=403;
            $message=$status>=500 && !($e instanceof HttpExceptionInterface) ? 'تعذر إتمام الطلب. حاول مجدداً.' : $e->getMessage();
            $headers=$e instanceof HttpExceptionInterface ? $e->getHeaders() : [];
            return response()->json(['success'=>false,'message'=>$message?:'فشل الطلب.',
                'errors'=>$e instanceof ValidationException ? $e->errors() : (object)[]],$status,$headers,JSON_UNESCAPED_UNICODE);
        });
    })->create();

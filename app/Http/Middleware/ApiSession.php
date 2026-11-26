<?php
namespace App\Http\Middleware;
use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
class ApiSession
{
    public static function valid(Request $request): bool
    {
        $user=$request->user();
        if (!$user || !$user->active || (int) $request->session()->get('auth_version', 0) !== (int) $user->auth_version) {
            return false;
        }
        $policy = Setting::valueOf('security', ['sessionMinutes' => 480]);
        return time() - (int) $request->session()->get('last_activity', 0) <= max(5, (int) ($policy['sessionMinutes'] ?? 480)) * 60;
    }
    public function handle(Request $request, Closure $next)
    {
        $user=$request->user();
        if (!self::valid($request)) {
            Auth::logout(); $request->session()->invalidate(); $request->session()->regenerateToken();
            if ($user && !$user->active) {
                return response()->json([
                    'success' => false,
                    'message' => 'تم تجميد حسابك. تواصل مع الموارد البشرية.',
                    'errors' => (object) [],
                    'reason' => 'frozen',
                ], 401)->header('Cache-Control', 'private, no-store');
            }
            abort(401,'يلزم تسجيل الدخول.');
        }
        $request->session()->put('last_activity',time());
        if ($user->must_change && !in_array($request->path(),['api/auth/me','api/auth/password','api/auth/welcome','api/auth/logout','api/bootstrap'])) {
            abort(403,'يجب تغيير كلمة السر قبل متابعة استخدام التطبيق.');
        }
        if(!$user->must_change) \App\Services\R29\HrWorkflow::applyDue();
        return $next($request);
    }
}

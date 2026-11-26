<?php
namespace App\Http\Controllers;
use App\Models\{User,Employee,Role,Setting,AuditEvent};
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth,DB,Hash,Validator};
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    public function status(Request $r) {
        $authenticated = \App\Http\Middleware\ApiSession::valid($r);
        $account = $authenticated ? $r->user() : null;

        return $this->ok(['setupRequired'=>!User::exists(),'setupAllowed'=>(bool)config('mosh.allow_setup'),
            'authenticated'=>$authenticated,'csrfToken'=>csrf_token(),
            'welcomeAccountId'=>$account && empty($account->data['welcomeAcknowledgedAt']) ? $account->id : null,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function sessionStatus(Request $r) {
        $account = $r->user();
        $authenticated = \App\Http\Middleware\ApiSession::valid($r);

        return $this->ok([
            'authenticated' => $authenticated,
            'accountId' => $authenticated ? $account->id : null,
            'frozen' => $account !== null && !$account->active,
        ])->header('Cache-Control', 'private, no-store');
    }
    public function setup(Request $r) {
        abort_unless(config('mosh.allow_setup'),403,'الإعداد الأولي غير متاح.');
        $v=$r->validate(['name'=>'required|string|max:150','username'=>'required|string|min:3|max:80|regex:/^[\pL\pN_.@-]+$/u',
            'password'=>'required|string|min:8|max:128|confirmed']);
        $u=DB::transaction(function () use($v) {
            DB::table('app_locks')->where('id','business')->increment('revision');
            abort_if(User::exists(),409,'تم إنشاء الحساب الأساسي مسبقاً.');
            $eid='EMP-'.Str::ulid();
            Employee::create(['id'=>$eid,'data'=>['id'=>$eid,'name'=>$v['name'],'department'=>'الإدارة','active'=>true,'employmentStatus'=>'active'], 'department'=>'الإدارة','version'=>1]);
            $u=User::create(['id'=>'USR-'.Str::ulid(),'name'=>$v['name'],'username'=>mb_strtolower($v['username']),
                'password'=>$v['password'],'role_id'=>'app_programmer','employee_id'=>$eid,'department'=>'الإدارة',
                'data'=>['landing'=>'dashboard','taskScope'=>'all','moduleOverrides'=>[],'globalOverrides'=>[]],
                'active'=>true,'is_root'=>true,'must_change'=>false,'auth_version'=>1,'version'=>1]);
            Audit::record($u,'security','users',$u->id,'setup',null,$u->document());
            return $u;
        });
        $this->session($r,$u);
        return $this->ok(['user'=>$u->document(),'csrfToken'=>csrf_token()],201);
    }
    public function login(Request $r) {
        $v=$r->validate(['username'=>'required|string|max:80','password'=>'required|string|max:128']);
        $u=User::where('username',mb_strtolower(trim($v['username'])))->first();
        if ($u?->locked_until?->isFuture()) abort(429,'تم إيقاف المحاولات مؤقتاً. حاول لاحقاً.', ['Retry-After'=>(string)max(1,$u->locked_until->getTimestamp()-time())]);
        if (!$u || !Hash::check($v['password'],$u->password) || !$u->active) {
            if ($u) {
                $p=Setting::valueOf('security');
                $u->increment('failed_attempts');
                if ($u->failed_attempts >= ($p['maxAttempts']??5)) $u->update(['locked_until'=>now()->addMinutes($p['lockMinutes']??10),'failed_attempts'=>0]);
                Audit::record($u,'security','users',$u->id,'login_failed');
            } else Hash::check($v['password'],'$2y$12$7Vn6dtBfQw0HQLlxkxE7cOrTNwcVS4UonVcuAVU2AE.sVuMXFkPjK');
            abort(401,'اسم المستخدم أو كلمة السر غير صحيحة.');
        }
        $u->update(['failed_attempts'=>0,'locked_until'=>null,'last_login'=>now()]);
        $this->session($r,$u);
        Audit::record($u,'security','users',$u->id,'login');
        return $this->ok(['user'=>$u->document(),'csrfToken'=>csrf_token()]);
    }
    private function session(Request $r, User $u): void {
        Auth::login($u); $r->session()->regenerate();
        $r->session()->put(['auth_version'=>$u->auth_version,'last_activity'=>time()]);
    }
    public function logout(Request $r) {
        Audit::record($r->user(),'security','users',$r->user()->id,'logout');
        Auth::logout(); $r->session()->invalidate(); $r->session()->regenerateToken();
        return $this->ok(['csrfToken'=>csrf_token()]);
    }
    public function me(Request $r) { return $this->ok($r->user()->document()+$r->user()->effectivePermissions()); }
    public function acknowledgeWelcome(Request $request)
    {
        $account = $request->user();
        $input = $request->validate(['accountId' => 'required|string|max:190']);
        abort_unless($input['accountId'] === $account->id, 409, 'تغيّر حساب الدخول. سجّل الدخول من جديد.');

        $confirmedAt = DB::transaction(function () use ($account) {
            $locked = DB::table('app_locks')->where('id', 'business')->increment('revision');
            abort_unless($locked === 1, 503, 'تعذر حفظ الاطلاع حالياً. أعد المحاولة.');
            $current = User::whereKey($account->id)->lockForUpdate()->first();
            abort_unless($current?->active && $current->auth_version === $account->auth_version, 401, 'سجّل الدخول من جديد.');
            $data = $current->data ?? [];
            if (!empty($data['welcomeAcknowledgedAt'])) return $data['welcomeAcknowledgedAt'];

            $data['welcomeAcknowledgedAt'] = now()->toIso8601String();
            $current->update(['data' => $data, 'version' => $current->version + 1]);

            return $data['welcomeAcknowledgedAt'];
        }, 3);

        return $this->ok(['accountId' => $account->id, 'welcomeAcknowledgedAt' => $confirmedAt]);
    }

    public function password(Request $r) {
        $p=Setting::valueOf('security');
        $v=$r->validate(['current_password'=>'required|string','password'=>'required|string|min:'.max(6,$p['minLength']??6).'|max:128|confirmed']);
        $u=$r->user();
        abort_unless(Hash::check($v['current_password'],$u->password),422,'كلمة السر الحالية غير صحيحة.');
        abort_if(Hash::check($v['password'],$u->password),422,'اختر كلمة سر مختلفة عن الحالية.');
        $u->update(['password'=>$v['password'],'must_change'=>false,'auth_version'=>$u->auth_version+1,'version'=>$u->version+1]);
        $this->session($r,$u);
        Audit::record($u,'security','users',$u->id,'password_changed');
        return $this->ok(['user'=>$u->document(),'csrfToken'=>csrf_token()]);
    }
    public function security(Request $r) {
        abort_unless($r->user()->root(),403,'هذه الصلاحية للحساب الأساسي.');
        $s=Setting::findOrFail('security');
        if ($r->isMethod('PATCH')) {
            $v=$r->validate(['minLength'=>'sometimes|integer|between:6,128','maxAttempts'=>'sometimes|integer|between:1,20',
                'lockMinutes'=>'sometimes|integer|between:1,1440','sessionMinutes'=>'sometimes|integer|between:5,10080','forceNewPassword'=>'sometimes|boolean']);
            $old=$s->value; $v=array_replace($old,$v); $s->update(['value'=>$v,'version'=>$s->version+1]);
            Audit::record($r->user(),'security','settings','security','update',$old,$v);
        }
        return $this->ok($s->value);
    }
    public function audit(Request $r) {
        abort_unless($r->user()->globalPermission('auditLog'),403);
        if ($r->isMethod('DELETE')) {
            abort_unless($r->user()->root(),403);
            Setting::updateOrCreate(['id'=>'audit-cutoff'],['value'=>['at'=>now()->toIso8601String()]]);
            Audit::record($r->user(),'security','audit','security','clear_visible');
            return $this->ok(null);
        }
        $q=AuditEvent::where('module','security');
        if ($at=Setting::valueOf('audit-cutoff')['at']??null) $q->where('created_at','>=',$at);
        $rows=$q->latest('created_at')->paginate(min(100,max(1,(int)$r->input('perPage',50))));
        return $this->ok($rows->items(),200,['page'=>$rows->currentPage(),'perPage'=>$rows->perPage(),'total'=>$rows->total(),'lastPage'=>$rows->lastPage()]);
    }
}

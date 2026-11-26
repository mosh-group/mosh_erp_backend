<?php

namespace App\Http\Controllers;

use App\Http\Middleware\ApiSession;
use App\Models\{Employee, User};
use App\Support\{Audit, R19HrAccess};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class HrAccountStatusController extends Controller
{
    public function show(Request $request, string $employeeId)
    {
        $actor = $request->user();
        $this->authorizeRead($actor);
        $employee = Employee::findOrFail($employeeId);
        $account = User::where('employee_id', $employee->id)->first();

        return $this->ok($account ? $this->status($actor, $employee, $account) : null);
    }

    public function update(Request $request, string $employeeId)
    {
        $actor = $request->user();
        $this->authorizeRead($actor);
        abort_unless($actor->allows('hr', 'edit'), 403, 'تغيير حالة الحساب يحتاج صلاحية تعديل الموارد البشرية.');
        $input = $request->validate([
            'accountId' => 'required|string|max:190',
            'active' => 'required|boolean',
            '_version' => 'required|integer|min:1',
        ]);

        return DB::transaction(function () use ($request, $actor, $employeeId, $input) {
            $locked = DB::table('app_locks')->where('id', 'business')->increment('revision');
            abort_unless($locked === 1, 503, 'تعذر تأمين معاملة تغيير حالة الحساب.');

            $actor->refresh();
            abort_unless(ApiSession::valid($request), 401, 'يلزم تسجيل الدخول.');
            $this->authorizeRead($actor);

            $employee = Employee::findOrFail($employeeId);
            $account = User::where('employee_id', $employee->id)->lockForUpdate()->first();
            abort_unless(
                $account && $account->id === $input['accountId'] && $account->version === (int) $input['_version'],
                409,
                'تغيّر الحساب أو حالته. حدّث حالة الحساب ثم أعد المحاولة.'
            );
            abort_unless($this->canManage($actor, $account), 403, 'صلاحيات هذا الحساب تتطلب إدارة الحسابات.');

            $active = (bool) $input['active'];
            if ($active !== $account->active) {
                abort_if($active && !$this->canActivate($employee), 422, 'فعّل الملف الوظيفي قبل إعادة تفعيل حساب الدخول.');
                $before = ['active' => $account->active];
                // A new session version prevents pre-freeze sessions returning after reactivation.
                $account->update([
                    'active' => $active,
                    'auth_version' => $account->auth_version + 1,
                    'version' => $account->version + 1,
                ]);
                Audit::record($actor, 'security', 'users', $account->id, $active ? 'unfreeze' : 'freeze', $before, ['active' => $active]);
            }

            return $this->ok($this->status($actor, $employee, $account));
        }, 3);
    }

    private function authorizeRead(User $actor): void
    {
        abort_unless(
            $actor->active && !$actor->must_change && $actor->allows('hr', 'view') && $actor->allows('hr', 'details'),
            403,
            'عرض حالة الحساب يحتاج صلاحية تفاصيل الموظفين في الموارد البشرية.'
        );
    }

    private function canManage(User $actor, User $account): bool
    {
        if (!$actor->allows('hr', 'edit') || $actor->is($account) || $account->root()) {
            return false;
        }
        if ($actor->root()) {
            return true;
        }

        // Evaluate privileges even when the target is currently frozen.
        $target = clone $account;
        $target->active = true;
        return !$target->administration() && !$target->globalPermission('managePermissions');
    }

    private function canActivate(Employee $employee): bool
    {
        return empty($employee->data['archivedAt']) && R19HrAccess::activeEmployee($employee);
    }

    private function status(User $actor, Employee $employee, User $account): array
    {
        $canManage = $this->canManage($actor, $account);
        $activationBlocked = $canManage && !$account->active && !$this->canActivate($employee);

        return [
            'accountId' => $account->id,
            'active' => $account->active,
            '_version' => $account->version,
            'canChange' => $canManage && !$activationBlocked,
            'activationBlocked' => $activationBlocked,
        ];
    }
}

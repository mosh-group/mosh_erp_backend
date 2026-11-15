<?php
namespace App\Support;

use App\Models\{Employee, FileLink, Setting, StoredFile, User};
use Illuminate\Support\Facades\{DB, Validator};
use Illuminate\Support\Str;

final class R19HrWorkflow
{
    public const DISCIPLINARY_PREFIX = '_r19_hr_disciplinary_';
    public const OVERTIME_PREFIX = '_r19_hr_overtime_';
    public const POLICY_KEY = '_r19_hr_policy';

    public static function policy(): array
    {
        return array_merge(['_version' => 0, 'workdaysByMonth' => [], 'history' => []], R19Store::get(self::POLICY_KEY) ?? [],
            ['dailyHours' => 8, 'makeUpDeficitFirst' => true]);
    }

    public static function snapshot(User $actor, string $date, string $month): array
    {
        return DB::transaction(function () use ($actor, $date, $month) {
            $policy = self::policy(); $people = []; $attendance = []; $monthly = [];
            $decisions = R19Store::prefixed(self::OVERTIME_PREFIX);
            $byId = array_column($decisions, null, 'id'); $visibleIds = [];
            $today = now()->timezone(config('app.timezone', 'Asia/Damascus'))->format('Y-m-d');
            $zone = (string)config('app.timezone', 'Asia/Damascus'); $asOf = now()->getTimestampMs();
            foreach (Employee::orderBy('id')->get() as $employee) {
                if (!R19HrAccess::attendance($actor, $employee)) continue;
                $d = $employee->data ?? []; $eid = (string)$employee->id; $visibleIds[] = $eid;
                $person = ['id' => $eid, 'name' => (string)($d['name'] ?? ''), 'department' => (string)($d['department'] ?? ''),
                    'directManagerId' => R19HrAccess::directManagerId($employee)];
                $people[] = array_merge($person, ['employeeNo' => (string)($d['employeeNo'] ?? ''), 'jobTitle' => (string)($d['jobTitle'] ?? ''),
                    'active' => R19HrAccess::activeEmployee($employee), 'canRequest' => R19HrAccess::request($actor, $employee), 'canView' => true]);
                $records = is_array($d['attendanceRecords'] ?? null) ? $d['attendanceRecords'] : [];
                $calculate = fn($day) => R19Attendance::calculate($person, $day, $records, $byId[R19Attendance::id($eid, $day)] ?? null, $asOf, $zone);
                if ($row = $calculate($date)) $attendance[] = R19Attendance::contextual($actor, $employee, $row);
                $dates = [];
                foreach ($records as $record) {
                    if (!is_array($record)) continue;
                    $day = (string)($record['date'] ?? $record['workDate'] ?? '');
                    if (self::validDate($day) && substr($day, 0, 7) === $month && $day <= $today) $dates[$day] = true;
                }
                // Removed/corrected attendance must not leave invisible credit.
                foreach ($decisions as $decision) if (($decision['employeeId'] ?? '') === $eid && substr($decision['date'] ?? '', 0, 7) === $month) $dates[$decision['date']] = true;
                ksort($dates); $days = [];
                foreach (array_keys($dates) as $day) if ($row = $calculate($day)) $days[] = $row;
                $monthly[] = R19Attendance::monthly($person, $month, $days, $policy, $today);
            }
            $disciplinary = [];
            foreach (R19Store::prefixed(self::DISCIPLINARY_PREFIX) as $row) if (R19HrAccess::discipline($actor, $row)) $disciplinary[] = self::projectDisciplinary($actor, $row);
            usort($disciplinary, fn($a, $b) => strcmp($b['createdAt'], $a['createdAt']) ?: strcmp($b['id'], $a['id']));
            return ['role' => R19HrAccess::role($actor), 'capabilities' => R19HrAccess::capabilities($actor),
                'employees' => $people, 'disciplinary' => $disciplinary, 'attendance' => $attendance,
                'overtime' => array_values(array_filter($decisions, fn($r) => in_array($r['employeeId'] ?? '', $visibleIds, true))),
                'monthly' => $monthly, 'policy' => $policy, 'date' => $date, 'month' => $month,
                'timeZone' => $zone, 'asOf' => $asOf, 'revision' => DB::table('app_locks')->where('id', 'business')->value('revision')];
        });
    }

    public static function canReadDisciplinary(User $actor, string $id): bool
    {
        $row = R19Store::get(self::DISCIPLINARY_PREFIX.$id);
        return $row && R19HrAccess::discipline($actor, $row);
    }

    public static function disciplinaryFor(User $actor, string $id): array
    {
        $row = R19Store::get(self::DISCIPLINARY_PREFIX.$id);
        abort_unless($row && R19HrAccess::discipline($actor, $row), 404, 'الطلب غير متاح لهذا الحساب.');
        return self::projectDisciplinary($actor, $row);
    }

    private static function projectDisciplinary(User $actor, array $row): array
    {
        $stage = R19HrAccess::disciplineStage($actor, $row);
        return array_merge($row, ['canReview' => $stage !== '', 'decisionStage' => $stage]);
    }

    public static function createDisciplinary(User $actor, array $input): array
    {
        self::only($input, ['employeeId', 'type', 'reason', 'details', 'amount', 'currency', 'attachments', 'requestId']);
        Validator::make($input, ['employeeId' => 'required|string|max:190', 'type' => 'required|in:deduction,penalty',
            'reason' => 'required|string|max:4000', 'details' => 'required|string|max:20000',
            'amount' => 'nullable|numeric|gt:0|max:999999999999.99', 'currency' => 'nullable|string|regex:/^[A-Z]{3}$/D',
            'attachments' => 'sometimes|array|max:5', 'attachments.*.key' => 'required|string|max:190|distinct'])->validate();
        abort_unless(trim($input['reason']) !== '' && trim($input['details']) !== '', 422, 'اكتب السبب وتفاصيل الإجراء.');
        if ($input['type'] === 'deduction') {
            abort_unless(isset($input['amount'], $input['currency']) && preg_match('/^\d{1,12}(?:\.\d{1,2})?$/D', (string)$input['amount']), 422, 'حدد مبلغ الخصم وعملته بدقة منزلتين عشريتين كحد أقصى.');
        } else abort_if(!empty($input['amount']), 422, 'طلب العقوبة لا يتضمن مبلغ خصم.');
        $result = R19Store::commit($actor, 'hr.disciplinary.create', $input, function ($tx) use ($actor, $input) {
            $employee = Employee::lockForUpdate()->find($input['employeeId']);
            abort_unless($employee && R19HrAccess::request($actor, $employee), 403, 'طلب الخصم أو العقوبة متاح لمدير قسم الموظف فقط.');
            $id = 'HR19-'.Str::ulid(); $at = now()->toIso8601String();
            $attachments = self::attachments($actor, $id, $input['attachments'] ?? []);
            $row = ['id' => $id, 'employeeId' => (string)$employee->id, 'employeeName' => $employee->data['name'],
                'department' => $employee->data['department'], 'type' => $input['type'], 'reason' => trim($input['reason']),
                'details' => trim($input['details']), 'amount' => $input['type'] === 'deduction' ? bcadd((string)$input['amount'], '0', 2) : null,
                'currency' => $input['type'] === 'deduction' ? $input['currency'] : '', 'attachments' => $attachments,
                'status' => 'pending_admin', 'createdAt' => $at, 'updatedAt' => $at,
                'submittedBy' => (string)$actor->id, 'submittedByName' => (string)$actor->name, 'submittedByRole' => 'manager',
                'history' => [self::history($actor, 'manager', 'submit', 'إرسال الطلب إلى الإدارة', '', $at)]];
            $saved = R19Store::put(self::DISCIPLINARY_PREFIX.$id, $row, 0);
            Audit::record($actor, 'hr', 'r19-disciplinary', $id, 'create', null, $saved, $tx);
            return ['id' => $id];
        });
        return self::disciplinaryFor($actor, $result['id']);
    }

    public static function decideDisciplinary(User $actor, string $id, array $input): array
    {
        self::only($input, ['decision', 'note', 'version', 'requestId']);
        Validator::make($input, ['decision' => 'required|in:approve,reject', 'note' => 'nullable|string|max:4000', 'version' => 'required|integer|min:1'])->validate();
        $note = trim($input['note'] ?? '');
        abort_if($input['decision'] === 'reject' && $note === '', 422, 'سبب الرفض مطلوب.');
        $result = R19Store::commit($actor, 'hr.disciplinary.'.$id.'.decision', $input, function ($tx) use ($actor, $id, $input, $note) {
            $old = R19Store::get(self::DISCIPLINARY_PREFIX.$id);
            abort_unless($old && R19HrAccess::discipline($actor, $old), 404);
            $stage = R19HrAccess::disciplineStage($actor, $old);
            abort_unless($stage !== '', 403, 'اعتماد هذه المرحلة غير متاح لهذا الحساب.');
            abort_unless((int)$input['version'] === $old['_version'], 409, 'تغير الطلب؛ حدّثه قبل اتخاذ القرار.');
            $next = $old; $at = now()->toIso8601String();
            if ($input['decision'] === 'reject') {
                $next['status'] = 'rejected'; $next['rejectionReason'] = $note; $next['rejectedAt'] = $at; $next['rejectedBy'] = (string)$actor->id;
                $label = $stage === 'admin' ? 'رفض الإدارة' : 'رفض الموارد البشرية';
            } elseif ($stage === 'admin') {
                $next['status'] = 'pending_hr'; $next['adminApprovedAt'] = $at; $next['adminApprovedBy'] = (string)$actor->id;
                $label = 'موافقة الإدارة وإحالة إلى الموارد البشرية';
            } else {
                $next['status'] = 'approved'; $next['approvedAt'] = $at; $next['approvedBy'] = (string)$actor->id;
                $next['employeeNotifiedAt'] = $at;
                $label = 'اعتماد نهائي وحفظ في ملف الموظف وإشعاره';
            }
            $next['updatedAt'] = $at; $next['history'][] = self::history($actor, $stage, $input['decision'], $label, $note, $at);
            $saved = R19Store::put(self::DISCIPLINARY_PREFIX.$id, $next, $old['_version']);
            Audit::record($actor, 'hr', 'r19-disciplinary', $id, $input['decision'], $old, $saved, $tx);
            return ['id' => $id];
        });
        return self::disciplinaryFor($actor, $result['id']);
    }

    private static function attachments(User $actor, string $id, array $attachments): array
    {
        $result = [];
        foreach ($attachments as $item) {
            abort_unless(is_array($item) && !array_diff(array_keys($item), ['key']), 422, 'أرسل مفتاح المرفق المرفوع فقط.');
            $file = StoredFile::where('logical_key', $item['key'])->lockForUpdate()->first();
            abort_unless($file && (string)$file->owner_id === (string)$actor->id, 403, 'أحد المرفقات غير متاح لهذا الطلب.');
            abort_unless(\Illuminate\Support\Facades\Storage::disk('local')->exists($file->path), 422, 'ملف المرفق غير موجود؛ أعد رفعه.');
            FileLink::firstOrCreate(['file_id' => $file->id, 'resource' => 'r19-disciplinary', 'record_id' => $id]);
            $result[] = array_merge($file->document(), ['type' => $file->mime, 'createdAt' => $file->created_at->toIso8601String()]);
        }
        return $result;
    }

    public static function attendanceFor(User $actor, string $id, bool $lock = false): array
    {
        abort_unless(preg_match('/^OT19-(\d{4}-\d{2}-\d{2})-[a-f0-9]{32}$/D', $id, $match) && self::validDate($match[1]), 404);
        $date = $match[1]; $employee = null;
        $approval = R19Store::get(self::OVERTIME_PREFIX.$id);
        if ($approval) $employee = Employee::find((string)$approval['employeeId']);
        else foreach (Employee::orderBy('id')->cursor() as $candidate) if (hash_equals(R19Attendance::id((string)$candidate->id, $date), $id)) { $employee = $candidate; break; }
        abort_unless($employee && R19HrAccess::attendance($actor, $employee), 404, 'سجل الحضور غير متاح لهذا الحساب.');
        if ($lock) $employee = Employee::whereKey($employee->id)->lockForUpdate()->firstOrFail();
        abort_unless(R19Attendance::id((string)$employee->id, $date) === $id, 404);
        $row = R19Attendance::forEmployee($employee, $date, $approval);
        abort_unless($row, 404, 'لا توجد بيانات حضور لهذا اليوم.');
        return ['employee' => $employee, 'row' => R19Attendance::contextual($actor, $employee, $row), 'approval' => $approval];
    }

    public static function decideOvertime(User $actor, string $id, array $input): array
    {
        self::only($input, ['decision', 'minutes', 'note', 'version', 'sourceFingerprint', 'requestId']);
        Validator::make($input, ['decision' => 'required|in:full,partial,return,final,resubmit', 'minutes' => 'nullable|integer|min:0|max:960',
            'note' => 'nullable|string|max:4000', 'version' => 'required|integer|min:0', 'sourceFingerprint' => 'required|string|size:64|regex:/^[a-f0-9]+$/D'])->validate();
        $note = trim($input['note'] ?? '');
        abort_if(in_array($input['decision'], ['return', 'resubmit'], true) && $note === '', 422, 'اكتب سبب الإعادة أو ملاحظة إعادة الإرسال.');
        $result = R19Store::commit($actor, 'hr.overtime.'.$id.'.decision', $input, function ($tx) use ($actor, $id, $input, $note) {
            $current = self::attendanceFor($actor, $id, true); $r = $current['row']; $old = $current['approval'];
            abort_unless((int)$input['version'] === $r['_version'], 409, 'تغير قرار الساعات؛ حدّث السجل قبل الحفظ.');
            abort_unless(hash_equals($r['sourceFingerprint'], $input['sourceFingerprint']), 409, 'تغير سجل الحضور أو المدير؛ حدّثه وراجع الساعات مجدداً.');
            $stage = $r['decisionStage']; $decision = $input['decision'];
            $allowed = ['manager' => ['full', 'partial', 'return'], 'hr' => ['final', 'return'], 'employee' => ['resubmit']];
            abort_unless(in_array($decision, $allowed[$stage] ?? [], true), 403, 'هذا القرار غير متاح لهذه المرحلة أو لهذا الحساب.');
            $next = $old ?? ['id' => $id, 'employeeId' => $r['employeeId'], 'date' => $r['date'], 'history' => [], 'createdAt' => now()->toIso8601String()];
            $at = now()->toIso8601String(); $next['department'] = $r['department']; $next['employeeName'] = $r['name'];
            $next['sourceFingerprint'] = $r['sourceFingerprint']; $next['rawMinutesSnapshot'] = $r['extra'];
            $next['directManagerId'] = $r['directManagerId']; $next['updatedAt'] = $at;
            if ($r['sourceChanged']) {
                $next['history'][] = self::history($actor, $stage, 'source_changed', 'إعادة مراجعة بعد تغيير سجل الحضور أو المدير المباشر', '', $at);
                $next['managerMinutes'] = $next['finalMinutes'] = 0;
            }
            if ($decision === 'return') {
                $next['stage'] = $stage === 'manager' ? 'returned_employee' : 'returned_manager';
                $next['returnReason'] = $note; $next['managerMinutes'] = $next['finalMinutes'] = 0;
                $label = $stage === 'manager' ? 'إعادة الساعات إلى الموظف للمراجعة' : 'إعادة الساعات إلى المدير المباشر';
            } elseif ($decision === 'resubmit') {
                $next['stage'] = 'pending_manager'; $next['returnReason'] = ''; $next['managerMinutes'] = $next['finalMinutes'] = 0;
                $label = 'إعادة إرسال الساعات إلى المدير المباشر';
            } elseif ($stage === 'manager') {
                $minutes = $decision === 'full' ? $r['extra'] : (isset($input['minutes']) ? (int)$input['minutes'] : null);
                abort_unless(is_int($minutes) && $minutes > 0 && $minutes <= $r['extra'], 422, 'حدد مدة صحيحة ضمن الساعات الزائدة الفعلية.');
                $next['managerMinutes'] = $minutes; $next['finalMinutes'] = 0; $next['stage'] = 'pending_hr';
                $next['managerApprovedAt'] = $at; $next['managerApprovedBy'] = (string)$actor->id; $next['returnReason'] = '';
                $label = $minutes === $r['extra'] ? 'موافقة المدير على كامل الساعات وإحالتها إلى الموارد البشرية' : 'موافقة المدير على جزء من الساعات وإحالتها إلى الموارد البشرية';
            } else {
                abort_unless($r['managerApproved'] > 0 && $r['complete'], 409, 'يلزم اعتماد المدير لسجل حضور مكتمل أولاً.');
                $next['managerMinutes'] = $next['finalMinutes'] = $r['managerApproved']; $next['stage'] = 'finalized';
                $next['finalApprovedAt'] = $at; $next['finalApprovedBy'] = (string)$actor->id; $next['returnReason'] = '';
                $label = 'اعتماد الموارد البشرية النهائي للساعات التي وافق عليها المدير';
            }
            $event = self::history($actor, $stage, $decision, $label, $note, $at);
            $event['rawMinutes'] = $r['extra']; $event['managerMinutes'] = $next['managerMinutes']; $event['finalMinutes'] = $next['finalMinutes'];
            $next['history'][] = $event;
            $saved = R19Store::put(self::OVERTIME_PREFIX.$id, $next, $r['_version']);
            Audit::record($actor, 'hr', 'r19-overtime', $id, $decision, $old, $saved, $tx);
            return ['id' => $id];
        });
        return self::attendanceFor($actor, $result['id'])['row'];
    }

    public static function updatePolicy(User $actor, array $input): array
    {
        self::only($input, ['month', 'workdays', 'version', 'requestId']);
        Validator::make($input, ['month' => 'required|date_format:Y-m', 'workdays' => 'required|integer|min:0|max:31', 'version' => 'required|integer|min:0'])->validate();
        $month = \DateTimeImmutable::createFromFormat('!Y-m', $input['month']);
        abort_unless($month && (int)$input['workdays'] <= (int)$month->format('t'), 422, 'أيام العمل تتجاوز عدد أيام الشهر.');
        R19Store::commit($actor, 'hr.policy.update', $input, function ($tx) use ($actor, $input) {
            abort_unless(R19HrAccess::hr($actor, 'edit'), 403, 'تحديد سياسة الدوام للموارد البشرية فقط.');
            $old = self::policy(); abort_unless((int)$input['version'] === $old['_version'], 409, 'تغيرت السياسة؛ حدّث البيانات أولاً.');
            $next = $old; $next['workdaysByMonth'][$input['month']] = (int)$input['workdays'];
            $next['updatedAt'] = now()->toIso8601String(); $next['updatedBy'] = (string)$actor->id;
            $event = self::history($actor, 'hr', 'policy', 'تحديد أيام العمل المطلوبة للشهر', '', $next['updatedAt']);
            $event['month'] = $input['month']; $event['workdays'] = (int)$input['workdays']; $next['history'][] = $event;
            $saved = R19Store::put(self::POLICY_KEY, $next, $old['_version']);
            Audit::record($actor, 'hr', 'r19-hr-policy', $input['month'], 'update', $old, $saved, $tx);
            return ['month' => $input['month']];
        });
        abort_unless(R19HrAccess::hr($actor, 'edit'), 403);
        return self::policy();
    }

    public static function noticesFor(User $actor): array
    {
        $result = [];
        foreach (R19Store::prefixed(self::DISCIPLINARY_PREFIX) as $r) {
            if (!R19HrAccess::discipline($actor, $r)) continue;
            $own = (string)$actor->employee_id === (string)$r['employeeId'];
            foreach ($r['history'] ?? [] as $index => $event) {
                $submitted = ($event['code'] ?? '') === 'submit';
                $adminApproved = ($event['role'] ?? '') === 'admin' && ($event['code'] ?? '') === 'approve';
                $final = ($event['role'] ?? '') === 'hr' && ($event['code'] ?? '') === 'approve';
                $management = !$own && (R19HrAccess::admin($actor) || R19HrAccess::hr($actor) ||
                    (string)$actor->id === (string)$r['submittedBy'] || in_array((string)$r['department'], R19HrAccess::managedDepartments($actor), true));
                $eligible = ($submitted && R19HrAccess::admin($actor)) || ($adminApproved && R19HrAccess::hr($actor)) ||
                    ($own && $final) || (!$submitted && $management && (($event['code'] ?? '') === 'reject' || $final));
                if (!$eligible) continue;
                $type = $r['type'] === 'deduction' ? 'خصم' : 'عقوبة';
                $title = $own && $final ? 'اعتماد '.$type.' في ملفك الوظيفي' : $event['action'].' · '.$r['employeeName'];
                $result[] = ['id' => 'hr19:'.$r['id'].':'.$index, 'type' => 'hr', 'title' => $title,
                    'body' => $r['reason'], 'createdAt' => $event['at'], 'source' => ($event['role'] ?? '') === 'hr' ? 'الموارد البشرية' : 'الإدارة',
                    'requestId' => $r['id'], 'recipientIds' => [(string)($actor->employee_id ?: $actor->id)],
                    'details' => ['requestId' => $r['id'], 'employeeId' => $r['employeeId']], 'urgent' => false];
            }
        }
        return $result;
    }

    private static function history(User $actor, string $role, string $code, string $action, string $note, string $at): array
    {
        return ['code' => $code, 'action' => $action, 'role' => $role, 'by' => (string)$actor->name,
            'byId' => (string)$actor->id, 'at' => $at, 'note' => $note];
    }

    public static function validDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed && $parsed->format('Y-m-d') === $date;
    }

    private static function only(array $input, array $allowed): void
    {
        abort_if((bool)array_diff(array_keys($input), $allowed), 422, 'الطلب يتضمن حقولاً غير مسموحة.');
    }
}

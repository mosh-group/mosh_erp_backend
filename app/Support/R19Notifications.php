<?php
namespace App\Support;

use App\Models\{Announcement, AdminDecision, Setting, SupportRequest, Task, User};
use Illuminate\Support\Facades\{DB, Validator};
use Illuminate\Support\Str;

/** Persistent delivery/receipts for the authenticated account. Looking at a
 * notice never modifies task state or task clocks. Existing business records
 * remain the source of authority, including after a role/assignment change. */
final class R19Notifications
{
    private const CIRCULAR_PREFIX = 'r19-administrative-circular:';

    public static function publishCircular(User $actor, array $input): array
    {
        abort_unless($actor->globalPermission('publishAdministrativeCirculars'), 403, 'لا تملك صلاحية نشر تعميم إداري.');
        $data = Validator::make($input, [
            'title' => 'required|string|max:250',
            'message' => 'required|string|max:10000',
            'days' => 'required|integer|between:1,3650',
            'displaySeconds' => 'nullable|integer|between:1,86400',
            'repeatOnRefresh' => 'sometimes|boolean',
            'requestId' => 'required|string|min:8|max:128',
        ])->validate();

        return R19Store::commit($actor, 'administrative-circular.publish', $data, function ($transaction) use ($actor, $data) {
            abort_unless($actor->globalPermission('publishAdministrativeCirculars'), 403, 'لا تملك صلاحية نشر تعميم إداري.');
            $publishedAt = now('UTC');
            $id = (string) Str::ulid();
            $document = [
                'id' => $id,
                'title' => $data['title'],
                'message' => $data['message'],
                'author' => $actor->name,
                'displaySeconds' => isset($data['displaySeconds']) ? (int) $data['displaySeconds'] : null,
                'repeatOnRefresh' => (bool) ($data['repeatOnRefresh'] ?? false),
                'publishedAt' => $publishedAt->toIso8601String(),
                'expiresAt' => $publishedAt->copy()->addDays((int) $data['days'])->toIso8601String(),
            ];
            R19Store::put(self::CIRCULAR_PREFIX.$id, $document, 0);
            Audit::record($actor, 'hr', 'administrative-circulars', $id, 'publish', null, $document, $transaction);

            return ['id' => $id, 'expiresAt' => $document['expiresAt']];
        });
    }

    private static function circularActive(array $document): bool
    {
        $expires = TaskExecutionClock::epoch($document['expiresAt'] ?? null);

        return $expires !== null && $expires > now()->getTimestampMs();
    }

    private static function activeCirculars(User $user): array
    {
        $recipient = self::owner($user);
        $result = [];
        foreach (Setting::query()->where('id', 'like', self::CIRCULAR_PREFIX.'%')
            ->where('value->expiresAt', '>', now('UTC')->toIso8601String())->get(['value']) as $row) {
            $circular = $row->value;
            $id = self::id('administrative-circular:'.$circular['id']);
            $result[$id] = [
                'id' => $id,
                'type' => 'administrative-circular',
                'title' => $circular['title'],
                'body' => $circular['message'],
                'recipientIds' => [$recipient],
                'source' => $circular['author'],
                'sourceId' => $circular['id'],
                'taskId' => '',
                'details' => '',
                'urgent' => false,
                'displaySeconds' => $circular['displaySeconds'] ?? null,
                'repeatOnRefresh' => (bool) ($circular['repeatOnRefresh'] ?? false),
                'createdAt' => $circular['publishedAt'],
                'expiresAt' => $circular['expiresAt'],
            ];
        }
        return $result;
    }

    private static function key(User $user): string
    {
        return 'r19-notifications:'.hash('sha256', (string) $user->id);
    }

    private static function owner(User $user): string
    {
        return (string) ($user->employee_id ?: $user->id);
    }

    private static function iso(mixed $value, mixed $fallback): string
    {
        $epoch = TaskExecutionClock::epoch($value);
        return $epoch !== null ? \Illuminate\Support\Carbon::createFromTimestampMs($epoch)->toIso8601String()
            : ($fallback instanceof \DateTimeInterface ? $fallback->format(DATE_ATOM) : (string) $fallback);
    }

    private static function id(string $source): string
    {
        return 'N19-'.hash('sha256', $source);
    }

    private static function addressed(User $user, array $document): bool
    {
        $keys = array_filter([(string) $user->id, (string) $user->employee_id]);
        if (array_key_exists('recipientIds', $document) && is_array($document['recipientIds'])) {
            return (bool) array_intersect($keys, array_map('strval', $document['recipientIds']));
        }
        if (is_array($document['recipientSnapshots'] ?? null)) {
            foreach ($document['recipientSnapshots'] as $person) {
                if (is_array($person) && in_array((string) ($person['employeeId'] ?? $person['id'] ?? ''), $keys, true)) return true;
            }
            return false;
        }
        return match ($document['targetType'] ?? 'all') {
            'employee' => in_array((string) ($document['targetValue'] ?? ''), $keys, true),
            'department' => $user->department !== '' && $user->department === ($document['targetValue'] ?? ''),
            'all', '' => true,
            default => false,
        };
    }

    private static function canSeeTask(User $user, array $document): bool
    {
        return empty($document['deletedAt']) && RecordAccess::own($user, $document)
            && RecordAccess::readable($user, 'tasks', $document, 'details');
    }
    private static function canReviewTask(User $user, array $task, array $managed): bool
    {
        $module = RecordAccess::module('tasks', $task);
        return empty($task['deletedAt']) && ($task['status'] ?? '') === 'in_progress'
            && ($task['employeeState'] ?? '') === 'submitted'
            && in_array($task['department'] ?? '', $managed, true)
            && $user->allows($module, 'view') && $user->allows($module, 'approve')
            && RecordAccess::readable($user, 'tasks', $task, 'details');
    }

    private static function managedDepartments(User $user): array
    {
        if (!$user->active || !$user->employee_id) return [];
        return array_keys(array_filter(DepartmentManagers::effective(),
            fn ($id, $department) => $id === (string)$user->employee_id && $user->manages($department), ARRAY_FILTER_USE_BOTH));
    }

    private static function reviewNoticeId(User $user, array $task): string
    {
        $cycle = $task['workTiming']['finishedAt'] ?? '';
        foreach (array_reverse($task['workChat'] ?? []) as $message) {
            if (str_starts_with((string)($message['id'] ?? ''), 'MR49/submit/')) {
                $cycle = $message['id'];
                break;
            }
        }
        return self::id('task:'.$task['id'].':'.self::owner($user).':approval:'.$cycle);
    }

    private static function sourceRows(User $user): array
    {
        $result = [];
        $recipient = self::owner($user);
        if ($user->employee_id) foreach (Task::where('employee_id', $user->employee_id)->get() as $task) {
            $d = $task->document();
            if (!self::canSeeTask($user, $d) || !empty($d['sharedTeamContainer']) || ($d['sharedNodeType'] ?? '') === 'team') continue;
            // Existing delivered notices stay available after closure. Do not
            // manufacture an upgrade backlog for old completed work.
            if (in_array(strtolower((string) ($d['status'] ?? '')), ['completed','cancelled','canceled','deleted','done','archived','archived_invoiced','archived_no_invoice','invoiced','awaiting_invoice','no_invoice'], true)) continue;
            $title = (string) (($d['title'] ?? '') ?: ($d['service'] ?? 'مهمة'));
            $urgent = (bool) ($d['isUrgent'] ?? $d['urgent'] ?? (($d['priority'] ?? '') === 'urgent'));
            $base = ['type' => 'task', 'taskId' => (string) $task->id, 'recipientIds' => [$recipient],
                'source' => (string) ($d['department'] ?? 'مدير القسم'), 'sourceId' => '',
                'details' => '', 'urgent' => $urgent];
            // R21 attention policy: routine task assignment belongs in the task inbox, not
            // the alert center. An alert is created only for a real attention event:
            // urgency (at creation or when enabled later) or a revision return below.
            if ($urgent) {
                $urgentAt = $d['urgentUpdatedAt'] ?? $d['assignedAt'] ?? $d['createdAt'] ?? null;
                $urgentKey = (string) ($d['urgentUpdatedAt'] ?? $d['assignedAt'] ?? $d['createdAt'] ?? 'urgent');
                $id = self::id('task:'.$task->id.':'.$recipient.':urgent:'.$urgentKey);
                $changedLater = !empty($d['urgentUpdatedAt']) && !empty($d['createdAt'])
                    && TaskExecutionClock::epoch($d['urgentUpdatedAt']) !== TaskExecutionClock::epoch($d['createdAt']);
                $result[$id] = $base + ['id' => $id, 'taskEvent' => 'urgent', 'title' => 'مهمة مستعجلة: '.$title,
                    'body' => ($changedLater ? 'تم تحديد المهمة كمستعجلة' : 'وصلتك مهمة مستعجلة').' · '.($d['customer'] ?? ''),
                    'createdAt' => self::iso($urgentAt, $task->created_at)];
            }
            $hasReturn = false;
            foreach (is_array($d['workChat'] ?? null) ? $d['workChat'] : [] as $index => $message) {
                if (!is_array($message)) continue;
                $messageId = (string) ($message['id'] ?? '');
                $text = (string) ($message['text'] ?? '');
                if (!str_starts_with($messageId, 'MR49/return/') && !(($message['system'] ?? false) && preg_match('/أعيدت المهمة للتعديل|أُعيدت المهمة للتعديل/u', $text))) continue;
                $hasReturn = true;
                $id = self::id('task:'.$task->id.':'.$recipient.':return:'.($messageId ?: hash('sha256', json_encode([$index, $message]))));
                $result[$id] = $base + ['id' => $id, 'taskEvent' => 'revision', 'title' => 'مطلوب تعديل: '.$title,
                    'body' => $text ?: 'راجع ملاحظات المدير ثم أكد استلام دورة التعديل.',
                    'createdAt' => self::iso($message['time'] ?? null, $task->created_at)];
            }
            if (!$hasReturn && !empty($d['returnedForRevision'])) {
                $id = self::id('task:'.$task->id.':'.$recipient.':legacy-return:'.($d['workTiming']['finishedAt'] ?? ''));
                $result[$id] = $base + ['id' => $id, 'taskEvent' => 'revision', 'title' => 'مطلوب تعديل: '.$title,
                    'body' => 'أعيدت المهمة للتعديل. راجع ملاحظات المدير قبل الاستلام.',
                    'createdAt' => self::iso($d['updatedAt'] ?? null, $task->created_at)];
            }
        }
        $managed = self::managedDepartments($user);
        if ($managed) foreach (Task::whereIn('department', $managed)->where('status', 'in_progress')->where('data->employeeState', 'submitted')->get() as $task) {
            $d = $task->document();
            if (!self::canReviewTask($user, $d, $managed)) continue;
            $id = self::reviewNoticeId($user, $d);
            $result[$id] = ['id'=>$id, 'type'=>'task', 'taskEvent'=>'approval', 'taskId'=>(string)$task->id,
                'recipientIds'=>[$recipient], 'source'=>$d['department'], 'sourceId'=>'', 'details'=>'',
                'urgent'=>false, 'title'=>'مهمة بانتظار اعتمادك: '.($d['title'] ?: 'مهمة'),
                'body'=>'أُرسلت المهمة للمراجعة. افتح تفاصيلها للاعتماد أو الإعادة للتعديل.',
                'createdAt'=>self::iso($d['workTiming']['finishedAt'] ?? $d['updatedAt'] ?? null, $task->created_at)];
        }
        foreach (Announcement::all() as $announcement) {
            $d = $announcement->document();
            if (!empty($d['deletedAt']) || ($d['active'] ?? true) === false || !self::addressed($user, $d)
                || !RecordAccess::readable($user, 'announcements', $d, 'details')) continue;
            $start = TaskExecutionClock::epoch($d['publishAt'] ?? $d['startAt'] ?? null);
            $end = TaskExecutionClock::epoch($d['expiresAt'] ?? $d['endAt'] ?? null);
            if ($start !== null && $start > now()->getTimestampMs() || $end !== null && $end <= now()->getTimestampMs()) continue;
            $id = self::id('announcement:'.$announcement->id);
            $result[$id] = ['id' => $id, 'type' => 'announcement', 'title' => (string) ($d['title'] ?? 'تعميم جديد'),
                'body' => (string) ($d['message'] ?? $d['body'] ?? ''), 'recipientIds' => [$recipient],
                'source' => (string) ($d['sourceDepartment'] ?? $d['createdBy'] ?? 'الموارد البشرية'),
                'sourceId' => (string) $announcement->id, 'taskId' => '',
                'details' => is_string($d['details'] ?? null) ? $d['details'] : '',
                'urgent' => (bool) ($d['isUrgent'] ?? $d['urgent'] ?? (($d['priority'] ?? '') === 'urgent')),
                'createdAt' => self::iso($d['publishAt'] ?? $d['createdAt'] ?? null, $announcement->created_at)];
            $readBy = is_array($d['readBy'] ?? null) ? $d['readBy'] : [];
            $receipt = $d['readAtBy'][$recipient] ?? null;
            if ($receipt || in_array($recipient, array_map('strval', $readBy), true)) {
                $result[$id]['readAt'] = self::iso($receipt, $announcement->updated_at);
            }
        }
        // R24 purchasing approvals are addressed to the exact configured account,
        // independently of whether the approver belongs to Administration.
        // Ordinary new requests never generate an employee task notification.
        foreach (SupportRequest::where('data->type','purchase')->get() as $purchase) {
            $p=$purchase->data['operation18']['r24']??[];
            if(!in_array($p['stage']??'',['quotation_pending','purchase_pending'],true))continue;
            if (R24PurchaseRules::expected($p)!==(string)$user->id)continue;
            $stage=$p['stage'];$number=(int)($p['position']??0)+1;
            $identity=(string)$purchase->id.':'.$stage.':'.$number.':'.count($p['history']??[]);
            $nid=self::id('r24:purchasing:approval:'.$identity);
            $d=$purchase->document();
            $result[$nid]=['id'=>$nid,'type'=>'r24-purchase-approval',
              'title'=>($stage==='quotation_pending'?'اعتماد طلب عرض سعر: ':'اعتماد الشراء: ').($d['title']??'طلب شراء'),
              'body'=>'طلب شراء ينتظر موافقتك في المرحلة '.$number.'، من نوع '.($p['policyName']??'المشتريات').'.',
              'recipientIds'=>[$recipient], 'source'=>'قسم المشتريات',
              'sourceId'=>(string)$purchase->id,'taskId'=>'',
              'details'=>['purchaseId'=>(string)$purchase->id,'stage'=>$stage],
              'urgent'=>false,
              'createdAt'=>self::iso($purchase->updated_at?->toIso8601String(),$purchase->created_at)];
        }
        foreach (R19HrWorkflow::noticesFor($user) as $notice) {
            $id = self::id('hr:'.($notice['id'] ?? ''));
            $requestId = (string) ($notice['requestId'] ?? $notice['details']['requestId'] ?? '');
            $result[$id] = ['id' => $id, 'type' => 'hr', 'title' => (string) ($notice['title'] ?? 'إشعار إداري'),
                'body' => (string) ($notice['body'] ?? ''), 'recipientIds' => [$recipient],
                'source' => (string) ($notice['source'] ?? 'الموارد البشرية'), 'sourceId' => '', 'taskId' => '',
                'details' => ['requestId' => $requestId], 'urgent' => false,
                'createdAt' => (string) ($notice['createdAt'] ?? '')];
        }
        // Administration records are delivered through the existing durable
        // receipt/archiving subsystem, not through a new browser-only inbox.
        foreach (R19Store::prefixed('_r24_admin_alert_') as $a) {
            if (!in_array($recipient,array_map('strval',$a['recipientIds']??[]),true)) {
                if((string)($a['sentBy']??'')===(string)$user->id && !empty($a['reply'])) {
                    $key=(string)($a['id']??'');$nid=self::id('r24:alert:reply:'.$key.':'.($a['replyAt']??''));
                    $result[$nid]=['id'=>$nid,'type'=>'r24-admin-reply','sourceId'=>$key,'taskId'=>'',
                        'title'=>'وصل رد على تنبيه إداري','body'=>(string)($a['reply']??''),
                        'source'=>(string)($a['employeeName']??'الموظف'),'recipientIds'=>[$recipient],
                        'urgent'=>false,'details'=>['alertId'=>$key],'createdAt'=>(string)($a['replyAt']??self::iso(null,now()))];
                }
                continue;
            }
            $nid=self::id('r24:alert:'.($a['id']??''));
            $result[$nid]=['id'=>$nid,'type'=>'r24-alert','sourceId'=>(string)($a['id']??''),'taskId'=>'',
                'title'=>'تنبيه إداري','body'=>(string)($a['message']??''),'source'=>(string)($a['sentByName']??'الإدارة'),
                'recipientIds'=>[$recipient],'urgent'=>false,'details'=>['alertId'=>(string)($a['id']??''),'replied'=>!empty($a['reply']),'replyText'=>(string)($a['reply']??'')],
                'createdAt'=>(string)($a['createdAt']??'')];
        }
        foreach (AdminDecision::all() as $decision) {
            $d=$decision->data??[];$key=(string)$decision->id;
            if(empty($d['notify']) || !in_array($recipient,array_map('strval',$d['recipientIds']??[]),true))continue;
            $isCancelled=($d['status']??'')==='cancelled';
            $revision=(int)($d['noticeRevision']??1);
            $event=$isCancelled?'cancel:'.($d['cancelNoticeAt']??''):('revision:'.$revision);
            $nid=self::id('r24:decision:'.$key.':'.$event);
            $result[$nid]=['id'=>$nid,'type'=>'r24-decision','sourceId'=>$key,'taskId'=>'',
                'title'=>($isCancelled?'إلغاء قرار إداري: ':($revision>1?'تعديل قرار إداري: ':'قرار إداري جديد: ')).($d['number']??$key),
                'body'=>($isCancelled?'تم إلغاء القرار. السبب: '.($d['cancelReason']??''):'الموضوع: '.($d['title']??'').'. تاريخ السريان: '.($d['effectiveDate']??$d['date']??'')),
                'source'=>(string)($d['owner']??'الإدارة'),'recipientIds'=>[$recipient],
                'urgent'=>false,'details'=>['decisionId'=>$key],
                'createdAt'=>$isCancelled?($d['cancelNoticeAt']??''):(($revision>1?$d['noticeChangedAt']??null:null)??($d['issuedAt']??$decision->created_at?->toIso8601String()??''))];
        }
        return $result;
    }

    /** Authorization is checked again for retained delivery snapshots. */
    private static function visible(User $user, array $notice): bool
    {
        if (!$user->active) return false;
        if (($notice['type'] ?? '') === 'task') {
            $task = Task::find((string) ($notice['taskId'] ?? ''));
            if (($notice['taskEvent'] ?? '') === 'approval') {
                if (!$task) return false;
                $d = $task->document();
                return self::canReviewTask($user, $d, self::managedDepartments($user))
                    && ($notice['id'] ?? '') === self::reviewNoticeId($user, $d);
            }
            if (!$task || !self::canSeeTask($user, $task->document())) return false;
            // Hide legacy routine-assignment notices that may already exist in an inbox
            // from R19/R20. Keep only urgency and revision events.
            $event = (string) ($notice['taskEvent'] ?? '');
            if (in_array($event, ['urgent', 'revision'], true)) return true;
            if (str_starts_with((string) ($notice['title'] ?? ''), 'مطلوب تعديل:')) return true;
            return !empty($notice['urgent']);
        }
        if (($notice['type'] ?? '') === 'administrative-circular') {
            return !empty($notice['readAt']) || self::circularActive($notice);
        }
        if (($notice['type'] ?? '') === 'announcement') {
            $row = Announcement::find((string) ($notice['sourceId'] ?? ''));
            $d = $row?->document();
            return $d && empty($d['deletedAt']) && ($d['active'] ?? true) !== false
                && self::addressed($user, $d) && RecordAccess::readable($user, 'announcements', $d, 'details');
        }
        if (($notice['type'] ?? '') === 'r24-alert') {
            $a=R19Store::get('_r24_admin_alert_'.(string)($notice['sourceId']??''));
            return $a && in_array(self::owner($user),array_map('strval',$a['recipientIds']??[]),true);
        }
        if (($notice['type'] ?? '') === 'r24-admin-reply') {
            $a=R19Store::get('_r24_admin_alert_'.(string)($notice['sourceId']??''));
            return $a && !empty($a['reply']) && (string)($a['sentBy']??'')===(string)$user->id;
        }
        if (($notice['type'] ?? '') === 'r24-decision') {
            $row=AdminDecision::find((string)($notice['sourceId']??''));$d=$row?->data??[];
            return !empty($d['notify']) && in_array(self::owner($user),array_map('strval',$d['recipientIds']??[]),true);
        }
        if (($notice['type'] ?? '') === 'r24-purchase-approval') {
            $row=SupportRequest::find((string)($notice['sourceId']??''));
            $d=$row?->data??[];$stage=$d['operation18']['r24']??[];
            return ($d['type']??'')==='purchase' &&
                R24PurchaseRules::expected($stage)===(string)$user->id &&
                ($notice['details']['stage']??'')===($stage['stage']??'');
        }
        if (($notice['type'] ?? '') === 'hr') {
            return R19HrWorkflow::canReadDisciplinary($user, (string) ($notice['details']['requestId'] ?? ''));
        }
        return false;
    }

    private static function merged(User $user, array $before): array
    {
        $items = is_array($before['items'] ?? null) ? $before['items'] : [];
        foreach (self::sourceRows($user) as $id => $notice) {
            $existing = $items[$id] ?? [];
            // Keep the explicit receipt on text changes and future refreshes.
            $items[$id] = array_merge($existing, $notice);
            if (!empty($existing['readAt'])) $items[$id]['readAt'] = $existing['readAt'];
        }
        return ['items' => $items];
    }

    private static function project(User $user, array $box): array
    {
        $stored = array_filter($box['items'] ?? [], fn($item) => is_array($item) && self::visible($user, $item));
        $items = array_values($stored + self::activeCirculars($user));
        foreach ($items as &$item) {
            if ($item['type'] === 'administrative-circular') {
                $item['recipientIds'] = [self::owner($user)];
                $item += ['displaySeconds' => null, 'repeatOnRefresh' => false];
            }
        }
        unset($item);
        usort($items, fn($a, $b) => strcmp($b['createdAt'] ?? '', $a['createdAt'] ?? '') ?: strcmp($b['id'], $a['id']));
        return ['items' => $items, '_version' => $box['_version'] ?? 0,
            'unread' => count(array_filter($items, fn($item) => empty($item['readAt']))),
            'serverTime' => now()->toIso8601String()];
    }

    public static function inbox(User $user): array
    {
        abort_unless($user->active, 403);
        $key = self::key($user);
        // Avoid a write or revision bump on the normal polling path.
        $before = R19Store::get($key) ?? ['items' => [], '_version' => 0];
        $next = self::merged($user, $before);
        if (R19Store::hash($next['items']) === R19Store::hash($before['items'])) return self::project($user, $before);
        $box = DB::transaction(function () use ($user, $key) {
            abort_unless(DB::table('app_locks')->where('id', 'business')->increment('revision') === 1, 503);
            $old = R19Store::get($key) ?? ['items' => [], '_version' => 0];
            $value = self::merged($user, $old);
            return R19Store::hash($value['items']) === R19Store::hash($old['items']) ? $old
                : R19Store::put($key, $value, $old['_version']);
        }, 3);
        return self::project($user, $box);
    }

    public static function acknowledge(User $user, string $id, array $input): array
    {
        Validator::make($input, ['requestId' => 'required|string|min:8|max:128'])->validate();
        R19Store::commit($user, 'notifications.ack:'.$id, $input, function ($tx) use ($user, $id) {
            $key = self::key($user);
            $old = R19Store::get($key) ?? ['items' => [], '_version' => 0];
            $next = self::merged($user, $old);
            $notice = $next['items'][$id] ?? self::activeCirculars($user)[$id] ?? null;
            abort_unless(is_array($notice) && self::visible($user, $notice), 404, 'الإشعار غير متاح لهذا الحساب.');
            if (!empty($notice['readAt'])) {
                // Another view may have acknowledged the source after this
                // inbox was loaded. Persist that receipt before replying.
                if (R19Store::hash($next['items']) !== R19Store::hash($old['items'])) {
                    R19Store::put($key, $next, $old['_version']);
                }
                return ['id' => $id];
            }
            $at = now()->toIso8601String();
            $notice['readAt'] = $at;
            $next['items'][$id] = $notice;
            R19Store::put($key, $next, $old['_version']);
            if (($notice['type'] ?? '') === 'announcement' && $user->employee_id) {
                $source = Announcement::findOrFail($notice['sourceId']);
                $d = $source->document();
                $owner = self::owner($user);
                $readBy = array_values(array_unique(array_merge(is_array($d['readBy'] ?? null) ? $d['readBy'] : [], [$owner])));
                $readAt = is_array($d['readAtBy'] ?? null) ? $d['readAtBy'] : [];
                $readAt[$owner] = $readAt[$owner] ?? $at;
                $change = Records::change($user, ['resource' => 'announcements', 'action' => 'update', 'id' => $source->id,
                    'version' => $source->version, 'data' => ['readBy' => $readBy, 'readAtBy' => $readAt]], $tx);
                $changes = [$change];
                BusinessRules::afterTransaction($user, $changes, $tx);
            }
            Audit::record($user, 'dashboard', 'r19-notifications', $id, 'acknowledge', null,
                ['id' => $id, 'readAt' => $at, 'accountId' => $user->id], $tx);
            return ['id' => $id];
        });
        $box = R19Store::get(self::key($user)) ?? ['items' => []];
        $notice = $box['items'][$id] ?? null;
        abort_unless(is_array($notice) && self::visible($user, $notice), 404);
        return ['id' => $id, 'readAt' => $notice['readAt'] ?? null, 'inbox' => self::project($user, $box)];
    }
}

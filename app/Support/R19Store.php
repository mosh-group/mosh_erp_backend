<?php
namespace App\Support;

use App\Models\{Setting, User};
use Illuminate\Support\Facades\{DB, Validator};
use Illuminate\Support\Str;

/** Private workflow documents in the existing settings table. No installation
 * migration, no defaults written on reads, and no generic settings route. */
final class R19Store
{
    public static function get(string $key): ?array
    {
        $row = Setting::find($key);
        return $row ? array_merge($row->value ?? [], ['_version' => $row->version]) : null;
    }

    /** Call from commit(): the business lock serializes generic record writes
     * too; this conditional update additionally prevents lost updates. */
    public static function put(string $key, array $value, int $expected): array
    {
        unset($value['_version']);
        if ($expected === 0) {
            abort_if(Setting::whereKey($key)->exists(), 409, 'تغير السجل؛ حدّث البيانات قبل الحفظ.');
            Setting::create(['id' => $key, 'value' => $value, 'version' => 1]);
        } else {
            $changed = Setting::whereKey($key)->where('version', $expected)->update([
                'value' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'version' => $expected + 1, 'updated_at' => now(),
            ]);
            abort_unless($changed === 1, 409, 'تغير السجل؛ حدّث البيانات قبل الحفظ.');
        }
        return array_merge($value, ['_version' => $expected + 1]);
    }

    public static function documents(string $prefix): array
    {
        return self::prefixed($prefix);
    }

    public static function prefixed(string $prefix): array
    {
        return Setting::whereRaw('substr(id, 1, ?) = ?', [strlen($prefix), $prefix])->orderBy('id')->get()
            ->map(fn($r) => array_merge($r->value ?? [], ['_version' => $r->version]))->all();
    }

    public static function hash(mixed $value): string
    {
        $canonical = function ($item) use (&$canonical) {
            if (!is_array($item)) return $item;
            if (!array_is_list($item)) ksort($item);
            return array_map($canonical, $item);
        };
        return hash('sha256', json_encode($canonical($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** The stored response should contain identifiers, not an authorization
     * snapshot. Re-project it for the current user after an idempotent retry. */
    public static function commit(User $actor, string $scope, array $input, callable $change): array
    {
        abort_unless($actor->active, 403);
        Validator::make($input, ['requestId' => ['required', 'string', 'min:8', 'max:128', 'regex:/^[A-Za-z0-9_.:-]+$/D']])->validate();
        $key = 'r19:'.$input['requestId'];
        $hash = self::hash([$scope, $input]);
        return DB::transaction(function () use ($actor, $key, $hash, $scope, $change) {
            $locked = DB::table('app_locks')->where('id', 'business')->increment('revision');
            abort_unless($locked === 1, 503, 'تهيئة قفل معاملات التطبيق غير مكتملة.');
            // Re-read the same actor object used by the command callback after
            // the lock, so a concurrently revoked role is not a stale grant.
            $actor->refresh();
            abort_unless($actor->active && !$actor->must_change, 403, 'صلاحية الحساب تغيرت؛ أعد تسجيل الدخول.');
            $previous = DB::table('transactions')->where('actor_id', $actor->id)->where('idempotency_key', $key)->first();
            if ($previous) {
                abort_unless(hash_equals($previous->request_hash, $hash), 409, 'مفتاح الطلب مستخدم لعملية مختلفة.');
                return json_decode($previous->response, true, 512, JSON_THROW_ON_ERROR);
            }
            $tx = (string) Str::ulid();
            $result = $change($tx);
            DB::table('transactions')->insert([
                'id' => $tx, 'actor_id' => $actor->id, 'idempotency_key' => $key,
                'request_hash' => $hash,
                // The normal Undo endpoint must reject a completed approval,
                // rather than treating an empty change list as a safe undo.
                'changes' => json_encode([['resource' => 'r19-workflow', 'id' => $scope, 'before' => null, 'after' => $result]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'response' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'undo_of' => null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            return $result;
        }, 3);
    }
}

<?php

namespace App\Support;

use App\Models\{Setting, User};
use Illuminate\Support\Facades\DB;

final class TaskIdReservations
{
    private const COUNTER = 'task-number';
    private const RANGE = 'task-number-range:';
    private const MAX = 9007199254740991;

    public static function mayReserve(User $user): bool
    {
        if (!$user->active) return false;
        if ($user->root() || $user->globalPermission('createTasks')) return true;

        $departments = array_merge(
            [(string)$user->department],
            array_keys(Setting::valueOf('department-managers'))
        );
        $modules = array_unique(array_merge(
            ['tasks'],
            array_map(fn($department) => User::departmentApp((string)$department), $departments),
            array_column(config('mosh.spec.resources'), 'module'),
            array_keys($user->role?->data['modules'] ?? []),
            array_keys($user->data['moduleOverrides'] ?? []),
        ));
        foreach ($modules as $module) {
            if ($module !== 'dashboard' && $user->allows($module, 'create')) return true;
        }
        return false;
    }

    public static function reserve(User $user, int $count): array
    {
        return DB::transaction(function () use ($user, $count) {
            $current = self::lockCounter();
            abort_if($count > self::MAX - $current, 409, 'لا تتوفر أرقام مهام إضافية.');
            $first = $current + 1;
            $last = $current + $count;
            DB::table('sequences')->insert(['id' => self::bound($first).$user->id, 'value' => $last]);
            DB::table('sequences')->where('id', self::COUNTER)->update(['value' => $last]);
            return ['first' => $first, 'last' => $last];
        }, 3);
    }

    /** Called in the same transaction as the task insert. */
    public static function registerCreate(User $user, string $id): void
    {
        $number = self::safeNumber($id);
        if ($number === null) return;

        $current = self::lockCounter();
        if ($number > $current) {
            // Compatibility for an older client submitting a numeric ID without a reservation.
            DB::table('sequences')->where('id', self::COUNTER)->update(['value' => $number]);
            return;
        }

        $range = DB::table('sequences')
            ->where('id', '>=', self::RANGE)
            ->where('id', '<', self::bound($number + 1))
            ->orderByDesc('id')->lockForUpdate()->first();
        if ($range && $number <= (int)$range->value) {
            $owner = substr($range->id, strlen(self::RANGE) + 17);
            abort_unless($id === (string)$number && $owner === (string)$user->id, 409,
                'رقم المهمة محجوز. اطلب رقماً جديداً.');
        }
    }

    private static function lockCounter(): int
    {
        if (!DB::table('sequences')->where('id', self::COUNTER)->exists()
            && DB::table('sequences')->insertOrIgnore(['id' => self::COUNTER, 'value' => 0])) {
            $mysql = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
            $numeric = $mysql
                ? "id REGEXP '^[0-9]+$' AND CHAR_LENGTH(TRIM(LEADING '0' FROM id)) <= 16"
                : "id GLOB '[0-9]*' AND id NOT GLOB '*[^0-9]*' AND LENGTH(LTRIM(id, '0')) <= 16";
            $type = $mysql ? 'UNSIGNED' : 'INTEGER';
            $number = "CASE WHEN $numeric THEN CAST(id AS $type) END";
            $result = DB::table('tasks')->selectRaw("MAX($number) AS maximum_id")
                ->whereRaw("$number <= ?", [self::MAX])->value('maximum_id');
            DB::table('sequences')->where('id', self::COUNTER)->update(['value' => (int)$result]);
        }
        return (int)DB::table('sequences')->where('id', self::COUNTER)->lockForUpdate()->value('value');
    }

    private static function safeNumber(string $id): ?int
    {
        if (!ctype_digit($id)) return null;
        $digits = ltrim($id, '0');
        if (strlen($digits) > 16 || (strlen($digits) === 16 && strcmp($digits, (string)self::MAX) > 0)) {
            return null;
        }
        return (int)$digits;
    }

    private static function bound(int $number): string
    {
        return self::RANGE.str_pad((string)$number, 16, '0', STR_PAD_LEFT).':';
    }
}


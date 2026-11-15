<?php
namespace App\Services\R34;
/** Server-only command scope. It is never derived from HTTP data. */
final class CommandContext
{
    private static array $stack = [];
    public static function run(string $kind, string $id, callable $fn): mixed
    {
        self::$stack[] = [$kind, $id];
        try { return $fn(); } finally { array_pop(self::$stack); }
    }
    public static function permits(string $kind, string $id): bool
    {
        return in_array([$kind, $id], self::$stack, true);
    }
}

<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Setting extends Model {
 public $incrementing=false; protected $keyType='string'; protected $guarded=[];
 protected function casts(): array { return ['value'=>'array','version'=>'integer']; }
 private const READ_SNAPSHOT = 'mosh.read_only_settings_snapshot';

 /** Only wrap a read-only projection. The cache lives on this request and is
  * removed even when projection fails; it never spans logins or writes. */
 public static function readOnlySnapshot(callable $read): mixed {
  $request = app('request');
  if ($request->attributes->has(self::READ_SNAPSHOT)) return $read();
  $request->attributes->set(self::READ_SNAPSHOT, (object)['values'=>[]]);
  try { return $read(); }
  finally { $request->attributes->remove(self::READ_SNAPSHOT); }
 }
 public static function valueOf(string $key, mixed $default=[]): mixed {
  $snapshot = app('request')->attributes->get(self::READ_SNAPSHOT);
  if (!$snapshot) return static::find($key)?->value ?? $default;
  if (!array_key_exists($key, $snapshot->values)) $snapshot->values[$key] = static::find($key)?->value;
  return $snapshot->values[$key] ?? $default;
 }
}

<?php
namespace App\Support;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Support\Str;
class Audit
{
    public static function record(?User $actor, string $module, string $resource, string $id, string $action, ?array $before=null, ?array $after=null, ?string $transaction=null, string $message=''): void
    {
        foreach (['password','password_confirmation','current_password','passwordHash','passwordEncrypted','remember_token'] as $secret) {
            if ($before) unset($before[$secret]);
            if ($after) unset($after[$secret]);
        }
        AuditEvent::create(['id'=>(string)Str::ulid(),'actor_id'=>$actor?->id,'actor_name'=>$actor?->name??'',
            'module'=>$module,'resource'=>$resource,'record_id'=>$id,'action'=>$action,'before_data'=>$before,
            'after_data'=>$after,'transaction_id'=>$transaction,'message'=>$message,'created_at'=>now()]);
    }
}

<?php
namespace App\Services\Instagram;
use App\Models\{Customer, User};
use App\Support\{RecordAccess, R19Store};
final class SocialAccess
{
    private static function legacyAllowed(User $actor): bool
    {
        if(!$actor->allows('marketing','view'))return false;
        foreach(['connectInstagram','publishInstagramDirect','scheduleInstagramDirect','approveInstagramPosts','viewInstagramInsights','connectFacebook','publishFacebookDirect','scheduleFacebookDirect','approveFacebookPosts','viewFacebookInsights'] as $key)if($actor->globalPermission($key))return true;
        return false;
    }
    public static function allows(User $actor,string $action): bool
    {
        if (!$actor->active || $actor->must_change || ($actor->data['moduleOverrides']['social']['view']??null)===false || ($actor->data['moduleOverrides']['social'][$action]??null)===false) return false;
        if ($actor->allows('social',$action)) return true;
        // Moving a screen must not revoke an existing explicit publishing grant or broaden department rights.
        return self::legacyAllowed($actor) && $actor->allows('marketing',$action);
    }
    public static function customer(User $actor, string $id, string $action = 'view'): Customer
    {
        $actor->unsetRelation('role');
        $customer = Customer::findOrFail($id);
        abort_unless($actor->active && !$actor->must_change && empty($customer->data['deletedAt']) &&
            self::allows($actor, $action) && RecordAccess::readable($actor, 'customers', $customer->document(), 'details'), 403, 'لا تملك صلاحية إدارة محتوى هذا العميل.');
        return $customer;
    }
    public static function permission(User $actor, string $permission): bool
    {
        return $actor->active && !$actor->must_change && self::allows($actor,'view') && $actor->globalPermission($permission);
    }
    public static function canReadPost(User $actor, string $id): bool
    {
        $post = R19Store::get('r28:social:post:'.$id);
        if (!$post) return false;
        try { self::customer($actor, (string)$post['customerId']); return true; } catch (\Throwable $e) { return false; }
    }
    public static function capabilities(User $actor): array
    {
        return ['connect'=>self::permission($actor, 'connectInstagram') && self::allows($actor,'edit'), 'compose'=>self::allows($actor,'create'),
            'schedule'=>self::permission($actor, 'scheduleInstagramDirect'), 'direct'=>self::permission($actor, 'publishInstagramDirect'), 'approve'=>self::permission($actor, 'approveInstagramPosts'),
            'insights'=>self::permission($actor, 'viewInstagramInsights'), 'configure'=>$actor->root()];
    }
}

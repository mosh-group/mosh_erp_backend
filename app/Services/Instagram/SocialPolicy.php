<?php
namespace App\Services\Instagram;
/** Pure rules shared by live endpoints and the dependency-free acceptance suite. */
final class SocialPolicy
{
    public const SCOPES = ['instagram_business_basic','instagram_business_content_publish','instagram_business_manage_insights'];
    public const WORKABLE = ['queued','scheduled','preparing','processing','publishing','reconciling'];
    public static function httpsUrl(string $url): bool
    {
        $p = parse_url($url);
        return $p !== false && ($p['scheme'] ?? '') === 'https' && !empty($p['host']) && !isset($p['user']) && !isset($p['pass']) && !isset($p['fragment']) && !isset($p['query']);
    }
    public static function requestedStatus(string $mode, bool $direct): string
    {
        if (!in_array($mode, ['draft','publish','schedule'], true)) throw new \DomainException('خيار النشر غير صالح.');
        if ($mode === 'draft') return 'draft';
        if (!$direct) return 'pending_approval';
        return $mode === 'schedule' ? 'scheduled' : 'queued';
    }
    public static function canEdit(string $status): bool { return in_array($status, ['draft','rejected','failed','blocked','cancelled'], true); }
    public static function canCancel(string $status): bool { return in_array($status, ['draft','rejected','pending_approval','scheduled','queued','failed','blocked'], true); }
    public static function uncertainAfterPublish(string $kind): bool { return $kind === 'network'; }
    public static function accountPublic(array $row): array
    {
        return array_intersect_key($row, array_flip(['id','customerId','instagramId','username','accountType','status','expiresAt','connectedAt','_version']));
    }
    public static function postPublic(array $row): array
    {
        return array_intersect_key($row, array_flip(['id','customerId','accountId','caption','mediaKind','attachmentKey','attachmentName','mode','scheduledAt','status','createdAt','createdBy','createdById','submittedById','approvedAt','approvedBy','publishedAt','mediaId','permalink','lastError','history','_version']));
    }
    public static function signedRequest(string $raw, string $secret): ?array
    {
        if ($secret === '' || strlen($raw) > 10000 || substr_count($raw, '.') !== 1) return null;
        [$signature, $payload] = explode('.', $raw, 2);
        $decode = static function (string $v) { return base64_decode(strtr($v, '-_', '+/').str_repeat('=', (4-strlen($v)%4)%4), true); };
        $sig = $decode($signature); $body = $decode($payload);
        if ($sig === false || $body === false || !hash_equals(hash_hmac('sha256', $payload, $secret, true), $sig)) return null;
        $data = json_decode($body, true);
        if (!is_array($data) || strtoupper((string)($data['algorithm'] ?? '')) !== 'HMAC-SHA256' || !preg_match('/^\d{1,40}$/D', (string)($data['user_id'] ?? ''))) return null;
        return $data;
    }
}

<?php
namespace App\Support;

/** Pure, deterministic rules for staged purchasing; legacy requests never pass through this class. */
final class R24PurchaseRules
{
    private const STAGES = ['quotation', 'purchase'];
    public static function validatePolicy(array $policy): array
    {
        $id = trim((string)($policy['id'] ?? ''));
        $name = trim((string)($policy['name'] ?? ''));
        if (!preg_match('/^[a-zA-Z0-9_-]{2,48}$/D', $id) || preg_match_all('/./us', $name) < 2 || preg_match_all('/./us', $name) > 120) {
            throw new \DomainException('نوع الشراء أو اسمه غير صالح.');
        }
        $out = ['id' => $id, 'name' => $name, 'active' => (bool)($policy['active'] ?? true)];
        foreach (self::STAGES as $stage) {
            $chain = $policy[$stage.'Approvers'] ?? null;
            if (!is_array($chain) || !array_is_list($chain) || count($chain) < 1 || count($chain) > 3) {
                throw new \DomainException('حدد من مدير واحد إلى ثلاثة بالترتيب لكل مرحلة اعتماد.');
            }
            $ids = array_map(fn($a) => is_string($a) ? trim($a) : '', $chain);
            if (in_array('', $ids, true) || count(array_unique($ids)) !== count($ids)) {
                throw new \DomainException('لا يجوز تكرار نفس المدير ضمن المرحلة أو ترك مرحلة بلا مدير.');
            }
            foreach ($ids as $value) if (strlen($value) > 190) throw new \DomainException('معرّف المدير غير صالح.');
            $out[$stage.'Approvers'] = $ids;
        }
        return $out;
    }
    public static function initial(array $policy): array
    {
        $p = self::validatePolicy($policy);
        return [
            'policyId' => $p['id'], 'policyName' => $p['name'],
            'quotationApprovers' => $p['quotationApprovers'], 'purchaseApprovers' => $p['purchaseApprovers'],
            'stage' => 'quotation_pending', 'position' => 0, 'history' => [],
            'selectedQuoteId' => null, 'createdAt' => gmdate('c'),
        ];
    }
    public static function expected(array $state): ?string
    {
        $step = ($state['stage'] ?? '') === 'quotation_pending' ? 'quotation' :
            (($state['stage'] ?? '') === 'purchase_pending' ? 'purchase' : null);
        return $step === null ? null : ($state[$step.'Approvers'][(int)($state['position'] ?? 0)] ?? null);
    }
    /** A return goes to purchasing for rework. A rejection closes the request. */
    public static function decide(array $state, string $actorId, string $action, string $note = '', ?string $quoteId = null): array
    {
        if (!in_array($state['stage'] ?? '', ['quotation_pending','purchase_pending'], true)) {
            throw new \DomainException('الطلب ليس بانتظار اعتماد في هذه المرحلة.');
        }
        if (self::expected($state) !== $actorId) throw new \DomainException('هذه المرحلة مخصصة لمدير آخر.');
        if (!in_array($action, ['approve','return','reject'], true)) throw new \DomainException('قرار غير معروف.');
        if ($action !== 'approve' && strlen(trim($note)) < 3) throw new \DomainException('سبب الإعادة أو الرفض مطلوب.');
        $step = $state['stage'] === 'quotation_pending' ? 'quotation' : 'purchase';
        if ($step === 'purchase' && $action === 'approve' &&
            (int)$state['position'] === count($state['purchaseApprovers']) - 1 && !$quoteId) {
            throw new \DomainException('المدير صاحب الاعتماد النهائي لازم يختار عرض المورد.');
        }
        $next = $state;
        $next['history'][] = ['at'=>gmdate('c'),'actorId'=>$actorId,'stage'=>$step,'level'=>(int)$state['position']+1,'action'=>$action,'note'=>trim($note),'quoteId'=>$quoteId];
        if ($action === 'return') {
            $next['stage'] = 'returned'; $next['position'] = 0;
        } elseif ($action === 'reject') {
            $next['stage'] = 'rejected'; $next['position'] = 0;
        } elseif ((int)$next['position'] < count($next[$step.'Approvers']) - 1) {
            $next['position']++;
        } else {
            $next['stage'] = $step === 'quotation' ? 'pricing' : 'approved';
            $next['position'] = 0;
            if ($step === 'purchase') $next['selectedQuoteId'] = $quoteId;
        }
        return $next;
    }
    public static function enterPurchaseApproval(array $state): array
    {
        if (($state['stage'] ?? '') !== 'pricing') throw new \DomainException('مرحلة جمع الأسعار لم تكتمل.');
        $state['stage'] = 'purchase_pending'; $state['position'] = 0;
        return $state;
    }
    public static function resubmit(array $state): array
    {
        if (($state['stage'] ?? '') !== 'returned') throw new \DomainException('الطلب غير معاد للتعديل.');
        // Always restart the full approval sequence on a changed request.
        $state['stage'] = 'quotation_pending'; $state['position'] = 0;
        $state['selectedQuoteId'] = null;
        return $state;
    }
}

<?php
namespace App\Services\Instagram;
/** Never embed provider URLs, tokens, or the raw HTTP exception in logs. */
final class MetaException extends \RuntimeException
{
    public function __construct(public readonly string $kind, public readonly int $providerCode = 0)
    {
        parent::__construct(match ($kind) {
            'network' => 'تعذر التأكد من استجابة إنستغرام. لن نكرر نشر المحتوى تلقائياً.',
            'auth' => 'انتهت صلاحية الاتصال بإنستغرام أو أُلغيت. أعد ربط الحساب.',
            'permission' => 'لم يمنح تطبيق Meta الصلاحية المطلوبة لهذا الإجراء.',
            'rate' => 'وصل إنستغرام إلى حد الطلبات المسموح؛ حاول لاحقاً.',
            default => 'رفض إنستغرام الطلب أو أعاد استجابة غير مكتملة. رمز المزود: '.$providerCode,
        });
    }
}

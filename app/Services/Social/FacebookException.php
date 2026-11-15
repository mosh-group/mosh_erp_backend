<?php
namespace App\Services\Social;
final class FacebookException extends \RuntimeException
{
    public function __construct(public readonly string $kind,public readonly bool $ambiguous=false)
    {
        parent::__construct(match($kind){'auth'=>'انتهى تفويض Facebook؛ أعد الربط الرسمي.','permission'=>'صلاحيات تطبيق Meta أو الصفحة لا تسمح بهذا الإجراء.','rate'=>'وصل Facebook إلى حد الطلبات؛ أعد المحاولة لاحقاً.','network'=>'لم تصل نتيجة الطلب من Facebook؛ لا تُكرر النشر قبل التحقق.','format'=>'رد Facebook غير مكتمل؛ النتيجة غير مؤكدة.',default=>'رفض Facebook الطلب. راجع حالة الصفحة والصلاحيات ومواصفات الوسائط.'});
    }
}

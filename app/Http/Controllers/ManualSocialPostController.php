<?php

namespace App\Http\Controllers;

use App\Models\{FileLink, StoredFile, User};
use App\Services\Instagram\SocialAccess;
use App\Support\{Audit, R19Store};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class ManualSocialPostController extends Controller
{
    private const PREFIX = 'r36:social-manual:';
    public const ATTACHMENT_RESOURCE = 'r36-manual-social-post';

    public function index(Request $request)
    {
        $customerId = $request->validate(['customerId' => 'required|string|max:190'])['customerId'];
        $actor = $request->user();
        SocialAccess::customer($actor, $customerId);
        $posts = array_values(array_filter(R19Store::documents(self::PREFIX), fn ($post) => $post['customerId'] === $customerId));
        usort($posts, fn ($a, $b) => strcmp($b['createdAt'], $a['createdAt']));
        return $this->ok(['posts' => array_map(fn ($post) => self::project($actor, $post), $posts)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request)
    {
        $input = $request->validate([
            'customerId' => 'required|string|max:190',
            'platform' => 'required|in:facebook,instagram',
            'caption' => 'present|nullable|string|max:60000',
            'attachmentKey' => 'nullable|string|max:190',
            'requestId' => 'required|string|min:8|max:128',
        ]);
        $actor = $request->user();
        $saved = R19Store::commit($actor, 'manual-social-create', $input, function ($tx) use ($actor, $input) {
            SocialAccess::customer($actor, $input['customerId'], 'create');
            $file = isset($input['attachmentKey']) ? StoredFile::where('logical_key', $input['attachmentKey'])->firstOrFail() : null;
            if ($file) {
                abort_unless((string) $file->owner_id === (string) $actor->id, 403, 'المرفق غير متاح لك.');
                abort_unless(in_array($file->mime, ['image/jpeg', 'image/png', 'video/mp4'], true) &&
                    Storage::disk('local')->exists($file->path), 422, 'اختر صورة JPEG أو PNG أو فيديو MP4.');
            }
            $caption = trim((string) ($input['caption'] ?? ''));
            abort_if($caption === '' && !$file, 422, 'أدخل نصاً أو أرفق ملفاً للمنشور.');
            $id = (string) Str::ulid();
            $post = R19Store::put(self::PREFIX.$id, [
                'id' => $id, 'customerId' => $input['customerId'], 'platform' => $input['platform'],
                'caption' => $caption, 'attachmentKey' => $file?->logical_key,
                'attachmentName' => $file?->name, 'status' => 'pending_manual',
                'createdById' => (string) $actor->id, 'createdBy' => $actor->name, 'createdAt' => now()->toIso8601String(),
            ], 0);
            if ($file) FileLink::firstOrCreate(['file_id' => $file->id, 'resource' => self::ATTACHMENT_RESOURCE, 'record_id' => $id]);
            Audit::record($actor, 'social', 'manual-social-post', $id, 'create', null, $post, $tx);
            return ['id' => $id];
        });
        return $this->responseFor($actor, $saved['id'], 201);
    }

    public function published(Request $request, string $id)
    {
        $input = $request->validate([
            '_version' => 'required|integer|min:1',
            'url' => 'nullable|url:https|max:2000',
            'requestId' => 'required|string|min:8|max:128',
        ]);
        $actor = $request->user();
        R19Store::commit($actor, 'manual-social-published:'.$id, $input, function ($tx) use ($actor, $id, $input) {
            $post = self::find($id);
            SocialAccess::customer($actor, $post['customerId']);
            abort_unless(self::canMark($actor, $post), 403, 'لا تملك صلاحية تسجيل نشر هذا المحتوى.');
            abort_unless($post['_version'] === (int) $input['_version'], 409, 'تغيّر المحتوى؛ حدّث القائمة.');
            $url = $input['url'] ?? null;
            if ($url) {
                $host = strtolower((string) parse_url($url, PHP_URL_HOST));
                $domains = $post['platform'] === 'facebook' ? ['facebook.com', 'fb.watch'] : ['instagram.com'];
                abort_unless(collect($domains)->contains(fn ($domain) => $host === $domain || str_ends_with($host, '.'.$domain)) &&
                    !parse_url($url, PHP_URL_USER), 422, 'رابط المنشور لازم يكون من نفس المنصة.');
            }
            $next = R19Store::put(self::PREFIX.$id, array_replace($post, [
                'status' => 'manual_published', 'url' => $url,
                'publishedAt' => now()->toIso8601String(), 'publishedBy' => $actor->name,
            ]), $post['_version']);
            Audit::record($actor, 'social', 'manual-social-post', $id, 'record_manual_publish', $post, $next, $tx);
            return ['id' => $id];
        });
        return $this->responseFor($actor, $id);
    }

    private function responseFor(User $actor, string $id, int $status = 200)
    {
        $post = self::find($id);
        SocialAccess::customer($actor, $post['customerId']);
        return $this->ok(self::project($actor, $post), $status);
    }

    private static function find(string $id): array
    {
        return R19Store::get(self::PREFIX.$id) ?? abort(404);
    }

    private static function canMark(User $actor, array $post): bool
    {
        return $post['status'] === 'pending_manual' && (SocialAccess::allows($actor, 'edit') ||
            ($post['createdById'] === (string) $actor->id && SocialAccess::allows($actor, 'create')));
    }

    private static function project(User $actor, array $post): array
    {
        return $post + ['canMark' => self::canMark($actor, $post)];
    }

    public static function canReadAttachment(User $actor, string $id): bool
    {
        try {
            SocialAccess::customer($actor, self::find($id)['customerId']);
            return true;
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
            return false;
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return false;
        }
    }
}

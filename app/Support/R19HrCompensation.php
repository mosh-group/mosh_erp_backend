<?php

namespace App\Support;

use App\Models\{Employee, FileLink, StoredFile, User};
use Illuminate\Support\Facades\{DB, Storage, Validator};

final class R19HrCompensation
{
    public const FILE_KIND = 'hr-compensation';
    private const RESOURCE = 'r19-hr-compensation';
    private const SETTINGS_PREFIX = '_r19_hr_compensation_';
    private const AMOUNTS = ['monthlySalary', 'foodAllowance', 'transportAllowance', 'phoneAllowance'];

    public static function canRead(User $actor): bool
    {
        return R19HrAccess::hr($actor, 'view') && R19HrAccess::hr($actor, 'details');
    }

    public static function canEdit(User $actor): bool
    {
        return self::canRead($actor) && R19HrAccess::hr($actor, 'edit');
    }

    public static function authorizeUpload(User $actor, ?string $kind): void
    {
        if ($kind === self::FILE_KIND) {
            abort_unless(self::canEdit($actor), 403);
        }
    }

    public static function canReadFile(User $actor, StoredFile $file): bool
    {
        if (!self::canRead($actor)) {
            return false;
        }

        return FileLink::where('file_id', $file->id)->where('resource', self::RESOURCE)->exists()
            || ((string) $file->owner_id === (string) $actor->id && !$file->links()->exists());
    }

    public static function show(User $actor, string $id): array
    {
        abort_unless(self::canRead($actor), 403);

        return DB::transaction(function () use ($actor, $id) {
            $employee = Employee::findOrFail($id);
            $record = R19Store::get(self::SETTINGS_PREFIX.$id) ?? [];
            $attachment = !empty($record['fileId'])
                ? StoredFile::includingHrCompensation()->where('kind', self::FILE_KIND)->find($record['fileId'])
                : null;
            $amounts = array_replace(array_fill_keys(self::AMOUNTS, null), array_intersect_key($record, array_flip(self::AMOUNTS)));

            return array_merge($amounts, [
                'employeeId' => $id,
                'raiseDate' => $record['raiseDate'] ?? null,
                'attachment' => $attachment?->document(),
                'canEdit' => empty($employee->data['archivedAt']) && self::canEdit($actor),
                '_version' => $record['_version'] ?? 0,
            ]);
        });
    }

    public static function save(User $actor, string $id, array $input): array
    {
        abort_unless(self::canEdit($actor), 403);
        $amountRule = ['present', 'nullable', 'numeric', 'regex:/^[0-9]{1,12}(?:\.[0-9]{1,2})?$/D'];
        $values = Validator::make($input, array_merge(array_fill_keys(self::AMOUNTS, $amountRule), [
            'raiseDate' => ['present', 'nullable', 'date_format:Y-m-d'],
            'attachmentKey' => ['present', 'nullable', 'string', 'max:190'],
            '_version' => ['required', 'integer', 'min:0'],
            'requestId' => ['required', 'string', 'min:8', 'max:128', 'regex:/^[A-Za-z0-9_.:-]+$/D'],
        ]))->validate();

        R19Store::commit($actor, self::RESOURCE.':'.$id, $values, function ($transaction) use ($actor, $id, $values) {
            abort_unless(self::canEdit($actor), 403);
            $employee = Employee::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_if(!empty($employee->data['archivedAt']), 422, 'استعد الملف المؤرشف قبل تعديل التعويضات.');

            $key = self::SETTINGS_PREFIX.$id;
            $old = R19Store::get($key);
            $version = $old['_version'] ?? 0;
            abort_unless((int) $values['_version'] === $version, 409, 'تغير السجل؛ حدّث البيانات قبل الحفظ.');
            $fileId = self::linkAttachment($actor, $id, $values['attachmentKey']);
            $record = ['raiseDate' => $values['raiseDate'], 'fileId' => $fileId];
            foreach (self::AMOUNTS as $field) {
                $record[$field] = $values[$field] === null ? null : bcadd((string) $values[$field], '0', 2);
            }

            R19Store::put($key, $record, $version);
            Audit::record($actor, 'hr', self::RESOURCE, $id, 'update', transaction: $transaction);

            return ['id' => $id];
        });

        return self::show($actor, $id);
    }

    private static function linkAttachment(User $actor, string $employeeId, ?string $key): ?string
    {
        $current = FileLink::where(['resource' => self::RESOURCE, 'record_id' => $employeeId]);
        if ($key === null) {
            $current->delete();

            return null;
        }

        $file = StoredFile::includingHrCompensation()->where('kind', self::FILE_KIND)
            ->where('logical_key', $key)->lockForUpdate()->first();
        abort_unless($file?->kind === self::FILE_KIND, 422, 'ارفع ملف التعويضات من هذا التبويب.');
        $existingLink = (clone $current)->where('file_id', $file->id)->exists();
        abort_unless($existingLink || ((string) $file->owner_id === (string) $actor->id && !$file->links()->exists()), 403,
            'هذا المرفق مرتبط بملف آخر أو لا تملكه.');
        abort_unless(Storage::disk('local')->exists($file->path), 422, 'ملف المرفق غير موجود؛ أعد رفعه.');

        $current->where('file_id', '!=', $file->id)->delete();
        FileLink::firstOrCreate(['resource' => self::RESOURCE, 'record_id' => $employeeId, 'file_id' => $file->id]);

        return (string) $file->id;
    }
}


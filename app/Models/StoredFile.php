<?php

namespace App\Models;

use App\Support\R19HrCompensation;
use Illuminate\Database\Eloquent\{Builder, Model};

class StoredFile extends Model
{
    private const HR_COMPENSATION_SCOPE = 'private-hr-compensation';

    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];

    protected static function booted(): void
    {
        // Dedicated attachments are accessible through the HR file ACL.
        // Generic records and share/export flows cannot select them.
        static::addGlobalScope(self::HR_COMPENSATION_SCOPE, function (Builder $query) {
            $query->where($query->qualifyColumn('kind'), '!=', R19HrCompensation::FILE_KIND);
        });
    }

    public function scopeIncludingHrCompensation(Builder $query): Builder
    {
        return $query->withoutGlobalScope(self::HR_COMPENSATION_SCOPE);
    }

    public function links() { return $this->hasMany(FileLink::class, 'file_id'); }
    public function owner() { return $this->belongsTo(User::class, 'owner_id'); }
    public function document(): array { return ['key'=>$this->logical_key,'name'=>$this->name,'mime'=>$this->mime,'kind'=>$this->kind,'size'=>(int)$this->size]; }
}


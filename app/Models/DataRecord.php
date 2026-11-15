<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
abstract class DataRecord extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected function casts(): array { return ['data' => 'array','version' => 'integer']; }
    public function document(): array { return array_merge($this->data ?? [], ['id' => $this->data['id'] ?? $this->id, '_version' => $this->version]); }
}

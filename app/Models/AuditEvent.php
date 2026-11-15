<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class AuditEvent extends Model
{
    public $incrementing=false;
    protected $keyType='string';
    public $timestamps=false;
    protected $guarded=[];
    protected function casts(): array { return ['before_data'=>'array','after_data'=>'array','created_at'=>'datetime']; }
}

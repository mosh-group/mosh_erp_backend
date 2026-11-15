<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class StoredFile extends Model {
 public $incrementing=false; protected $keyType='string'; protected $guarded=[];
 public function links() { return $this->hasMany(FileLink::class,'file_id'); }
 public function owner() { return $this->belongsTo(User::class,'owner_id'); }
 public function document(): array { return ['key'=>$this->logical_key,'name'=>$this->name,'mime'=>$this->mime,'kind'=>$this->kind,'size'=>(int)$this->size]; }
}

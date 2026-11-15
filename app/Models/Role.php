<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Role extends Model {
 public $incrementing=false; protected $keyType='string'; protected $guarded=[];
 protected function casts(): array { return ['data'=>'array','protected'=>'boolean','version'=>'integer']; }
 public function users() { return $this->hasMany(User::class); }
 public function document(): array { return array_merge($this->data??[],['id'=>$this->id,'name'=>$this->name,'protected'=>$this->protected,'_version'=>$this->version]); }
}

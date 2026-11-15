<?php
namespace App\Models; use Illuminate\Database\Eloquent\Model;
class ZktecoDevice extends Model { protected $table='zkteco_devices'; protected $guarded=[]; public $incrementing=false; protected $keyType='string'; protected function casts():array{return ['active'=>'boolean','last_seen_at'=>'datetime','last_sync_at'=>'datetime','meta'=>'array'];} }

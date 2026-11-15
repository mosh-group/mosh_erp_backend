<?php
namespace App\Models; use Illuminate\Database\Eloquent\Model;
class ZktecoAttendanceEvent extends Model { protected $table='zkteco_attendance_events'; protected $guarded=[]; public $incrementing=false; protected $keyType='string'; protected function casts():array{return ['device_time'=>'datetime','received_at'=>'datetime','processed_at'=>'datetime','ignored_for_attendance'=>'boolean','payload'=>'array'];} }

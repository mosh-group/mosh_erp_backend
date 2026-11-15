<?php
namespace App\Models; use Illuminate\Database\Eloquent\Model;
class ZktecoEmployeeMapping extends Model { protected $table='zkteco_employee_mappings'; protected $guarded=[]; public function device(){return $this->belongsTo(ZktecoDevice::class,'device_id');} public function employee(){return $this->belongsTo(Employee::class,'employee_id');} }

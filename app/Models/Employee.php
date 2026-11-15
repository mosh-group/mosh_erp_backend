<?php
namespace App\Models;
class Employee extends DataRecord
{
    protected $table = 'employees';
    public function tasks() { return $this->hasMany(Task::class, 'employee_id'); }
    public function account() { return $this->hasOne(User::class, 'employee_id'); }
}

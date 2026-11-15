<?php
namespace App\Models;
class WorkPresence extends DataRecord
{
    protected $table = 'work_presence';
    public function employee() { return $this->belongsTo(Employee::class, 'employee_id'); }
}

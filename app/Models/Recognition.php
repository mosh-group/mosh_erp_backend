<?php
namespace App\Models;
class Recognition extends DataRecord
{
    protected $table = 'recognition';
    public function employee() { return $this->belongsTo(Employee::class, 'employee_id'); }
}

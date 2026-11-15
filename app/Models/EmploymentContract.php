<?php
namespace App\Models;
class EmploymentContract extends DataRecord
{
    protected $table = 'employment_contracts';
    public function employee() { return $this->belongsTo(Employee::class, 'employee_id'); }
}

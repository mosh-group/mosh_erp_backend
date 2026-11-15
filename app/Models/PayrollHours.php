<?php
namespace App\Models;
class PayrollHours extends DataRecord
{
    protected $table = 'payroll_hours';
    public function employee() { return $this->belongsTo(Employee::class, 'employee_id'); }
}

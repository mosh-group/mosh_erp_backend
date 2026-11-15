<?php
namespace App\Models;
class HrApproval extends DataRecord
{
    protected $table = 'hr_approvals';
    public function employee() { return $this->belongsTo(Employee::class, 'employee_id'); }
}

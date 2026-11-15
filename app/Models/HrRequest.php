<?php
namespace App\Models;
class HrRequest extends DataRecord
{
    protected $table = 'hr_requests';
    public function employee() { return $this->belongsTo(Employee::class, 'employee_id'); }
}

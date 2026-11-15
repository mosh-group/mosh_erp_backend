<?php
namespace App\Models;
class OffboardingCase extends DataRecord
{
    protected $table = 'offboarding';
    public function employee() { return $this->belongsTo(Employee::class, 'employee_id'); }
}

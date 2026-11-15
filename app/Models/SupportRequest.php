<?php
namespace App\Models;
class SupportRequest extends DataRecord
{
    protected $table = 'support_requests';
    public function task() { return $this->belongsTo(Task::class, 'task_id'); }
    public function employee() { return $this->belongsTo(Employee::class, 'employee_id'); }
}

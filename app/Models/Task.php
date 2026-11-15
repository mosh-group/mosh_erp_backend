<?php
namespace App\Models;
class Task extends DataRecord
{
    protected $table = 'tasks';
    public function employee() { return $this->belongsTo(Employee::class, 'employee_id'); }
    public function customer() { return $this->belongsTo(Customer::class, 'customer_id'); }
    public function parent() { return $this->belongsTo(Task::class, 'parent_id'); }
    public function quotation() { return $this->belongsTo(Quotation::class, 'quotation_id'); }
    public function children() { return $this->hasMany(Task::class, 'parent_id'); }
}

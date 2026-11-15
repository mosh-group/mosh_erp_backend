<?php
namespace App\Models;
class FieldWork extends DataRecord
{
    protected $table = 'field_work';
    public function employee() { return $this->belongsTo(Employee::class, 'employee_id'); }
}

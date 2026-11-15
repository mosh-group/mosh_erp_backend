<?php
namespace App\Models;
class Quotation extends DataRecord
{
    protected $table = 'quotations';
    public function customer() { return $this->belongsTo(Customer::class, 'customer_id'); }
}

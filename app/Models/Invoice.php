<?php
namespace App\Models;
class Invoice extends DataRecord
{
    protected $table = 'invoices';
    public function customer() { return $this->belongsTo(Customer::class, 'customer_id'); }
    public function quotation() { return $this->belongsTo(Quotation::class, 'quotation_id'); }
}

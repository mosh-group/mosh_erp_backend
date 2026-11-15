<?php
namespace App\Models;
class ServiceContract extends DataRecord
{
    protected $table = 'service_contracts';
    public function customer() { return $this->belongsTo(Customer::class, 'customer_id'); }
}

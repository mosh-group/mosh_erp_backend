<?php
namespace App\Models;
class Reservation extends DataRecord
{
    protected $table = 'reservations';
    public function item() { return $this->belongsTo(StockItem::class, 'item_id'); }
    public function warehouse() { return $this->belongsTo(Warehouse::class, 'warehouse_id'); }
    public function supportRequest() { return $this->belongsTo(SupportRequest::class, 'request_id'); }
}

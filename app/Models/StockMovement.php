<?php
namespace App\Models;
class StockMovement extends DataRecord
{
    protected $table = 'stock_movements';
    public function item() { return $this->belongsTo(StockItem::class, 'item_id'); }
    public function fromWarehouse() { return $this->belongsTo(Warehouse::class, 'from_warehouse_id'); }
    public function toWarehouse() { return $this->belongsTo(Warehouse::class, 'to_warehouse_id'); }
    public function employee() { return $this->belongsTo(Employee::class, 'employee_id'); }
    public function supportRequest() { return $this->belongsTo(SupportRequest::class, 'request_id'); }
}

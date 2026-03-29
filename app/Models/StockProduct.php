<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockProduct extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'stocks_product';

    // Disable timestamps since the table doesn't have created_at/updated_at columns
    public $timestamps = false;

    protected $fillable = [
        'stock_id',
        'stock_qty',
        'product_id',
        'stock_type',
        'delivery_products_id',
    ];

    protected $casts = [
        'stock_qty' => 'integer',
        'deleted_at' => 'datetime'
    ];

    /**
     * Get the stock that owns the stock product
     */
    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    /**
     * Get the product that is being stocked
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the delivery product that this stock product is based on
     */
    public function deliveryProduct(): BelongsTo
    {
        return $this->belongsTo(DeliveryProduct::class, 'delivery_products_id');
    }

    /**
     * Get stock type options
     */
    public static function getStockTypeOptions()
    {
        return [
            'in' => 'Stock In (Customer Delivery/Transfer Received)',
            'out' => 'Stock Out (Transfer Sent/Return)',
            'sold-out' => 'Sold Out (Daily Usage/Sales)'
        ];
    }
}
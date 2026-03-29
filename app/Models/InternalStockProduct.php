<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InternalStockProduct extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'internal_stocks_products';

    // Disable timestamps since the table doesn't have created_at/updated_at columns
    public $timestamps = false;

    protected $fillable = [
        'internal_stock_id',
        'product_id',
        'stock_qty',
    ];

    protected $casts = [
        'stock_qty' => 'integer',
        'deleted_at' => 'datetime'
    ];

    /**
     * Get the internal stock that owns this product
     */
    public function internalStock(): BelongsTo
    {
        return $this->belongsTo(InternalStock::class, 'internal_stock_id');
    }

    /**
     * Get the product
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}


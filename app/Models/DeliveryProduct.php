<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryProduct extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'delivery_products';
    
    // Disable timestamps since the table doesn't have created_at and updated_at columns
    public $timestamps = false;

    protected $fillable = [
        'delivery_id',
        'delivery_qty',
        'product_id',
        'return_qty',
        'return_reason',
        'return_date',
        'return_status'
    ];

    protected $casts = [
        'delivery_qty' => 'integer',
        'return_qty' => 'integer',
        'return_date' => 'date',
        'deleted_at' => 'datetime'
    ];

    /**
     * Get the delivery that owns the delivery product
     */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    /**
     * Get the product that is being delivered
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get return status options
     */
    public static function getReturnStatusOptions()
    {
        return [
            'pending' => 'Pending',
            'approved' => 'Approved',
            'rejected' => 'Rejected'
        ];
    }

    /**
     * Scope to filter by return status
     */
    public function scopeByReturnStatus($query, $status)
    {
        return $query->where('return_status', $status);
    }

    /**
     * Scope to filter by return date range
     */
    public function scopeByReturnDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('return_date', [$startDate, $endDate]);
    }

    /**
     * Check if product has returns
     */
    public function hasReturns()
    {
        return $this->return_qty > 0;
    }

    /**
     * Get net delivery quantity (delivery_qty - return_qty)
     */
    public function getNetDeliveryQuantityAttribute()
    {
        return $this->delivery_qty - $this->return_qty;
    }
}

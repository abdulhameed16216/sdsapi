<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'vendor_id',
        'product_id',
        'type',
        'quantity',
        'previous_quantity',
        'new_quantity',
        'from_vendor_id',
        'to_vendor_id',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'previous_quantity' => 'decimal:2',
        'new_quantity' => 'decimal:2',
    ];

    /**
     * Get the user who made the transaction.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the vendor for the transaction.
     */
    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * Get the product for the transaction.
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the source vendor for transfers.
     */
    public function fromVendor()
    {
        return $this->belongsTo(Vendor::class, 'from_vendor_id');
    }

    /**
     * Get the destination vendor for transfers.
     */
    public function toVendor()
    {
        return $this->belongsTo(Vendor::class, 'to_vendor_id');
    }

    /**
     * Scope for usage transactions
     */
    public function scopeUsage($query)
    {
        return $query->where('type', 'usage');
    }

    /**
     * Scope for transfer transactions
     */
    public function scopeTransfers($query)
    {
        return $query->whereIn('type', ['transfer_in', 'transfer_out']);
    }

    /**
     * Scope for transactions by date range
     */
    public function scopeByDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    /**
     * Scope for transactions by vendor
     */
    public function scopeByVendor($query, $vendorId)
    {
        return $query->where('vendor_id', $vendorId);
    }

    /**
     * Scope for transactions by product
     */
    public function scopeByProduct($query, $productId)
    {
        return $query->where('product_id', $productId);
    }
}

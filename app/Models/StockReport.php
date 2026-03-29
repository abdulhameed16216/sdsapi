<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockReport extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'stock_reports';

    protected $fillable = [
        'customer_id',
        'product_id',
        'report_date',
        'stock_in_qty',
        'transfer_received_qty',
        'transfer_outside_qty',
        'current_availability_qty',
        'stock_used_qty',
        'notes',
        'created_by'
    ];

    protected $casts = [
        'report_date' => 'date',
        'stock_in_qty' => 'integer',
        'transfer_received_qty' => 'integer',
        'transfer_outside_qty' => 'integer',
        'current_availability_qty' => 'integer',
        'stock_used_qty' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime'
    ];

    /**
     * Get the customer that owns the stock report.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the product that owns the stock report.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the user who created the stock report.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Scope to filter by customer.
     */
    public function scopeByCustomer($query, $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    /**
     * Scope to filter by product.
     */
    public function scopeByProduct($query, $productId)
    {
        return $query->where('product_id', $productId);
    }

    /**
     * Scope to filter by date range.
     */
    public function scopeByDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('report_date', [$startDate, $endDate]);
    }

    /**
     * Scope to filter by specific date.
     */
    public function scopeByDate($query, $date)
    {
        return $query->where('report_date', $date);
    }

    /**
     * Scope to get today's entries.
     */
    public function scopeToday($query)
    {
        return $query->where('report_date', today());
    }

    /**
     * Calculate total stock in (stock in + transfer received).
     */
    public function getTotalStockInAttribute()
    {
        return $this->stock_in_qty + $this->transfer_received_qty;
    }

    /**
     * Calculate net stock movement.
     */
    public function getNetStockMovementAttribute()
    {
        return $this->getTotalStockInAttribute() - $this->transfer_outside_qty;
    }

    /**
     * Update or create stock report for a specific customer, product, and date.
     */
    public static function updateOrCreateReport($customerId, $productId, $date, $data = [])
    {
        return static::updateOrCreate(
            [
                'customer_id' => $customerId,
                'product_id' => $productId,
                'report_date' => $date
            ],
            array_merge($data, [
                'updated_at' => now()
            ])
        );
    }
}

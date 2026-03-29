<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Stock extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'customer_id',
        'customer_floor_id',
        'transfer_status',
        'from_cust_id',
        't_date',
        'delivery_id',
        'created_by',
        'modified_by',
        'status',
        'deleted_by'
    ];

    protected $casts = [
        't_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    // Relationships
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function fromCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'from_cust_id');
    }

    public function stockProducts(): HasMany
    {
        return $this->hasMany(StockProduct::class, 'stock_id');
    }

    public function customerFloor(): BelongsTo
    {
        return $this->belongsTo(CustomerFloor::class, 'customer_floor_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function modifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'modified_by');
    }

    public function deleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class, 'delivery_id');
    }

    // Scopes
    public function scopeIn($query)
    {
        return $query->whereHas('stockProducts', function($q) {
            $q->where('stock_type', 'in');
        });
    }

    public function scopeOut($query)
    {
        return $query->whereHas('stockProducts', function($q) {
            $q->where('stock_type', 'out');
        });
    }

    public function scopeByCustomer($query, $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    public function scopeByFromCustomer($query, $customerId)
    {
        return $query->where('from_cust_id', $customerId);
    }

    public function scopeByDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('t_date', [$startDate, $endDate]);
    }

    public function scopeCustomerDeliveries($query)
    {
        return $query->whereHas('stockProducts', function($q) {
            $q->where('stock_type', 'in');
        })->whereNull('from_cust_id');
    }

    public function scopeTransfersIn($query)
    {
        return $query->whereHas('stockProducts', function($q) {
            $q->where('stock_type', 'in');
        })->whereNotNull('from_cust_id');
    }

    public function scopeTransfersOut($query)
    {
        return $query->whereHas('stockProducts', function($q) {
            $q->where('stock_type', 'out');
        })->whereNotNull('from_cust_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    // Helper methods

    public static function getStatusOptions()
    {
        return [
            'active' => 'Active',
            'inactive' => 'Inactive'
        ];
    }

    /**
     * Get total quantity for this stock record
     */
    public function getTotalQuantityAttribute()
    {
        return $this->stockProducts->sum('stock_qty');
    }

    /**
     * Get products count for this stock record
     */
    public function getProductsCountAttribute()
    {
        return $this->stockProducts->count();
    }
}
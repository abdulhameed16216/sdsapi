<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Delivery extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'delivery';

    protected $fillable = [
        'customer_id',
        'delivery_status',
        'from_cust_id',
        'prepare_date',
        'delivery_date',
        'delivery_type',
        'created_by',
        'modified_by',
        'status',
        'deleted_by'
    ];

    protected $casts = [
        'delivery_date' => 'date',
        'prepare_date'=> 'date',
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

    public function deliveryProducts(): HasMany
    {
        return $this->hasMany(DeliveryProduct::class, 'delivery_id');
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

    // Scopes
    public function scopeIn($query)
    {
        return $query->where('delivery_type', 'in');
    }

    public function scopeOut($query)
    {
        return $query->where('delivery_type', 'out');
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
        return $query->whereBetween('delivery_date', [$startDate, $endDate]);
    }

    public function scopeCustomerDeliveries($query)
    {
        return $query->where('delivery_type', 'in')->whereNull('from_cust_id');
    }

    public function scopeTransfersIn($query)
    {
        return $query->where('delivery_type', 'in')->whereNotNull('from_cust_id');
    }

    public function scopeTransfersOut($query)
    {
        return $query->where('delivery_type', 'out')->whereNotNull('from_cust_id');
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

    public static function getDeliveryTypeOptions()
    {
        return [
            'in' => 'Delivery In (Customer Delivery/Transfer Received)',
            'out' => 'Delivery Out (Transfer Sent)',
            'transfer' => 'Transfer (Stock Transfer Between Customers)'
        ];
    }

    /**
     * Get total quantity for this delivery record
     */
    public function getTotalQuantityAttribute()
    {
        return $this->deliveryProducts->sum('delivery_qty');
    }

    /**
     * Get products count for this delivery record
     */
    public function getProductsCountAttribute()
    {
        return $this->deliveryProducts->count();
    }
}

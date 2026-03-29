<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockAvailabilityGroup extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'stock_availability_groups';

    protected $fillable = [
        'customer_id',
        'date',
        'time',
        'notes',
        'created_by'
    ];

    protected $casts = [
        'date' => 'date',
        'time' => 'datetime:H:i:s',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime'
    ];

    /**
     * Get the customer that owns the stock availability group.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the user who created the stock availability group.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the stock availability records for this group.
     */
    public function stockAvailabilityRecords(): HasMany
    {
        return $this->hasMany(StockAvailability::class, 'stock_group_id');
    }

    /**
     * Scope to filter by customer.
     */
    public function scopeByCustomer($query, $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    /**
     * Scope to filter by date range.
     */
    public function scopeByDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('date', [$startDate, $endDate]);
    }

    /**
     * Scope to filter by specific date.
     */
    public function scopeByDate($query, $date)
    {
        return $query->where('date', $date);
    }

    /**
     * Get total products count for this group
     */
    public function getTotalProductsAttribute()
    {
        return $this->stockAvailabilityRecords()->count();
    }

    /**
     * Get total closing quantity for this group
     */
    public function getTotalClosingQtyAttribute()
    {
        return $this->stockAvailabilityRecords()->sum('closing_qty');
    }
}

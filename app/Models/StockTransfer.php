<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTransfer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'from_customer_id',
        'to_customer_id',
        'transfer_date',
        'notes',
        'created_by'
    ];

    protected $casts = [
        'transfer_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime'
    ];

    /**
     * Get the stock records for this transfer
     */
    public function stocks()
    {
        return $this->hasMany(Stock::class, 'transfer_id');
    }

    /**
     * Get the customer that the stock is transferred from.
     */
    public function fromCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'from_customer_id');
    }

    /**
     * Get the customer that the stock is transferred to.
     */
    public function toCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'to_customer_id');
    }


    /**
     * Get the user who created the transfer.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Scopes
    public function scopeByFromCustomer($query, $customerId)
    {
        return $query->where('from_customer_id', $customerId);
    }

    public function scopeByToCustomer($query, $customerId)
    {
        return $query->where('to_customer_id', $customerId);
    }


    public function scopeByDate($query, $date)
    {
        return $query->whereDate('transfer_date', $date);
    }

    public function scopeByDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('transfer_date', [$startDate, $endDate]);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InternalStock extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'internal_stocks';

    protected $fillable = [
        'from_vendor_id',
        't_date',
        'notes',
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
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'from_vendor_id');
    }

    public function internalStockProducts(): HasMany
    {
        return $this->hasMany(InternalStockProduct::class, 'internal_stock_id');
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
     * Get total quantity for this internal stock record
     */
    public function getTotalQuantityAttribute()
    {
        return $this->internalStockProducts->sum('stock_qty');
    }

    /**
     * Get products count for this internal stock record
     */
    public function getProductsCountAttribute()
    {
        return $this->internalStockProducts->count();
    }
}


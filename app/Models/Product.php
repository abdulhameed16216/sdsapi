<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'code',
        'description',
        'size',
        'unit',
        'price',
        'product_image',
        'status',
        'notes',
        'minimum_threshold',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the user who created this product
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated this product
     */
    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Get the stocks for the product.
     */
    public function stocks()
    {
        return $this->hasMany(Stock::class);
    }

    /**
     * Get the stock transactions for the product.
     */
    public function stockTransactions()
    {
        return $this->hasMany(StockTransaction::class);
    }

    /**
     * Get the vendors that sell this product
     */
    public function vendors()
    {
        return $this->belongsToMany(Vendor::class, 'vendor_products', 'product_id', 'vendor_id')
            ->withPivot('status', 'created_at', 'updated_at')
            ->withTimestamps()
            ->wherePivot('status', 'active');
    }

    /**
     * Get all vendor product assignments (including inactive)
     */
    public function vendorProducts()
    {
        return $this->hasMany(VendorProduct::class);
    }

    /**
     * Scope for active products
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope for inactive products
     */
    public function scopeInactive($query)
    {
        return $query->where('status', 'inactive');
    }

    /**
     * Get status options
     */
    public static function getStatusOptions()
    {
        return [
            'active' => 'Active',
            'inactive' => 'Inactive'
        ];
    }

    /**
     * Get unit options
     */
    public static function getUnitOptions()
    {
        return [
            'piece' => 'Piece',
            'kg' => 'Kilogram',
            'liter' => 'Liter',
            'gram' => 'Gram',
            'ml' => 'Milliliter',
            'box' => 'Box',
            'pack' => 'Pack',
            'bottle' => 'Bottle',
            'can' => 'Can',
            'bag' => 'Bag'
        ];
    }
}

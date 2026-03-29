<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vendor extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'vendors';

    protected $fillable = [
        'vendor_name',
        'contact_person',
        'mobile',
        'telephone',
        'email',
        'address',
        'gst_no',
        'logo',
        'status',
        'created_by',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the machines for the vendor.
     */
    public function machines()
    {
        return $this->hasMany(Machine::class);
    }

    /**
     * Get the stocks for the vendor.
     */
    public function stocks()
    {
        return $this->hasMany(Stock::class);
    }

    /**
     * Get the attendances for the vendor.
     */
    public function attendances()
    {
        return $this->hasMany(Attendance::class);
    }

    /**
     * Get the documents for the vendor.
     */
    public function documents()
    {
        return $this->hasMany(Document::class);
    }

    /**
     * Get stock transactions for this vendor
     */
    public function stockTransactions()
    {
        return $this->hasMany(StockTransaction::class);
    }

    /**
     * Get the products assigned to this vendor
     */
    public function products()
    {
        return $this->belongsToMany(Product::class, 'vendor_products', 'vendor_id', 'product_id')
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
     * Scope for active vendors
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope for inactive vendors
     */
    public function scopeInactive($query)
    {
        return $query->where('status', 'inactive');
    }
}

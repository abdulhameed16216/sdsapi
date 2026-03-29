<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'customer_group_id',
        'name',
        'email',
        'phone',
        'mobile_number',
        'address',
        'city',
        'state',
        'country',
        'postal_code',
        'company_name',
        'contact_person',
        'customer_type',
        'nature_of_account',
        'status',
        'notes',
        'logo',
        'agreement_start_date',
        'agreement_end_date',
        'documents',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'agreement_start_date' => 'date',
        'agreement_end_date' => 'date',
        'documents' => 'array',
    ];

    /**
     * Customer group (this customer as a location under a group)
     */
    public function customerGroup()
    {
        return $this->belongsTo(CustomerGroup::class, 'customer_group_id');
    }

    /**
     * Floors under this customer (when this customer is used as location)
     */
    public function floors()
    {
        return $this->hasMany(CustomerFloor::class, 'location_id');
    }

    /**
     * Get the user who created this customer
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated this customer
     */
    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Get all customer product assignments (including inactive)
     */
    public function customerProducts()
    {
        return $this->hasMany(CustomerProduct::class);
    }

    /**
     * Scope for active customers
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope for inactive customers
     */
    public function scopeInactive($query)
    {
        return $query->where('status', 'inactive');
    }

    /**
     * Get customer type options
     */
    public static function getCustomerTypes()
    {
        return [
            'individual' => 'Individual',
            'business' => 'Business',
            'organization' => 'Organization'
        ];
    }

    /**
     * Get status options
     */
    public static function getStatusOptions()
    {
        return [
            'active' => 'Active',
            'inactive' => 'Inactive',
            'suspended' => 'Suspended'
        ];
    }

    /**
     * Get full logo URL
     */
    public function getLogoUrlAttribute()
    {
        if (!$this->logo) {
            return null;
        }
        
        // Handle old paths (with iupload) and new paths (without iupload)
        $logoPath = $this->logo;
        
        // If path contains 'iupload', remove it (migration from old to new structure)
        if (strpos($logoPath, 'files/iupload/') !== false) {
            $logoPath = str_replace('files/iupload/', 'files/', $logoPath);
        }
        
        // Also handle old paths that might be in storage or customers/ directly
        if (strpos($logoPath, 'storage/') === 0) {
            $logoPath = str_replace('storage/', 'files/', $logoPath);
        }
        if (strpos($logoPath, 'customers/') === 0 && strpos($logoPath, 'files/') === false) {
            $logoPath = 'files/' . $logoPath;
        }
        
        return url('/') . '/' . $logoPath;
    }

    /**
     * Get full document URLs
     */
    public function getDocumentsUrlsAttribute()
    {
        if (!$this->documents || !is_array($this->documents)) {
            return [];
        }
        
        $baseUrl = url('/');
        return array_map(function($path) use ($baseUrl) {
            return $baseUrl . '/' . $path;
        }, $this->documents);
    }
}

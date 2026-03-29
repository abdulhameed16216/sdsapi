<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

class CustomerMachine extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'customer_machines';

    protected $fillable = [
        'cust_id',
        'machine_id',
        'status',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime'
    ];

    /**
     * Get the customer that owns the assignment.
     */
    public function customer()
    {
        return $this->belongsTo(Customer::class, 'cust_id');
    }

    /**
     * Get the machine that is assigned.
     */
    public function machine()
    {
        return $this->belongsTo(Machine::class);
    }

    /**
     * Get the user who created the assignment.
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated the assignment.
     */
    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Scope a query to only include active assignments.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope a query to only include completed assignments.
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope a query to only include inactive assignments.
     */
    public function scopeInactive($query)
    {
        return $query->where('status', 'inactive');
    }

    /**
     * Scope a query to filter by customer.
     */
    public function scopeForCustomer($query, $customerId)
    {
        return $query->where('cust_id', $customerId);
    }

    /**
     * Scope a query to filter by machine.
     */
    public function scopeForMachine($query, $machineId)
    {
        return $query->where('machine_id', $machineId);
    }

    /**
     * Scope a query to filter by created date range.
     */
    public function scopeCreatedBetween($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    /**
     * Scope a query to filter by customer type (vendor assignments).
     */
    public function scopeVendorAssignments($query)
    {
        return $query->whereHas('customer', function ($q) {
            $q->where('customer_type', 'organization');
        });
    }

    /**
     * Scope a query to filter by regular customer assignments.
     */
    public function scopeCustomerAssignments($query)
    {
        return $query->whereHas('customer', function ($q) {
            $q->whereIn('customer_type', ['individual', 'business']);
        });
    }

    /**
     * Check if the assignment is active.
     */
    public function isActive()
    {
        return $this->status === 'active';
    }

    /**
     * Check if the assignment is completed.
     */
    public function isCompleted()
    {
        return $this->status === 'completed';
    }

    /**
     * Check if the assignment is inactive.
     */
    public function isInactive()
    {
        return $this->status === 'inactive';
    }

    /**
     * Get formatted created date.
     */
    public function getFormattedCreatedDateAttribute()
    {
        return $this->created_at ? $this->created_at->format('M d, Y') : null;
    }

    /**
     * Get status badge class.
     */
    public function getStatusBadgeClassAttribute()
    {
        return match($this->status) {
            'active' => 'badge-success',
            'inactive' => 'badge-secondary',
            'completed' => 'badge-primary',
            default => 'badge-secondary'
        };
    }

    /**
     * Get status label.
     */
    public function getStatusLabelAttribute()
    {
        return match($this->status) {
            'active' => 'Active',
            'inactive' => 'Inactive',
            'completed' => 'Completed',
            default => 'Unknown'
        };
    }

    /**
     * Get the assignment duration in days.
     */
    public function getAssignmentDurationAttribute()
    {
        if ($this->created_at) {
            return $this->created_at->diffInDays(Carbon::now());
        }
        return 0;
    }

    /**
     * Check if assignment is overdue (created but not completed after expected period).
     */
    public function isOverdue($expectedDays = 30)
    {
        if ($this->isCompleted()) {
            return false;
        }
        
        return $this->created_at && $this->created_at->addDays($expectedDays)->isPast();
    }

    /**
     * Get days since assignment.
     */
    public function getDaysSinceAssignmentAttribute()
    {
        if ($this->created_at) {
            return $this->created_at->diffInDays(Carbon::now());
        }
        return 0;
    }
}

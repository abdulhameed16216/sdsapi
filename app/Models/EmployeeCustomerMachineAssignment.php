<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

class EmployeeCustomerMachineAssignment extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'employee_customer_machine_assignments';

    protected $fillable = [
        'employee_id',
        'customer_id',
        'floor_id',
        'assigned_machine_id',
        'assigned_date',
        'notes',
        'status',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'assigned_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime'
    ];

    /**
     * Get the employee that is assigned.
     */
    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    /**
     * Get the customer that owns the assignment.
     */
    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    /**
     * Floor under the branch (customers_floor); null means branch pool / not allocated to a floor.
     */
    public function floor()
    {
        return $this->belongsTo(CustomerFloor::class, 'floor_id');
    }

    /**
     * Get the machine that is assigned.
     */
    public function machine()
    {
        return $this->belongsTo(Machine::class, 'assigned_machine_id');
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
     * Scope a query to filter by employee.
     */
    public function scopeForEmployee($query, $employeeId)
    {
        return $query->where('employee_id', $employeeId);
    }

    /**
     * Scope a query to filter by customer.
     */
    public function scopeForCustomer($query, $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    /**
     * Scope a query to filter by machine.
     */
    public function scopeForMachine($query, $machineId)
    {
        return $query->where('assigned_machine_id', $machineId);
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
}


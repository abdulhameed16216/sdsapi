<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MachineReading extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'customer_id',
        'machine_id',
        'reading_date',
        'reading_type',
    ];

    protected $casts = [
        'reading_date' => 'date',
    ];

    /**
     * Get the user who recorded the reading.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the customer for the reading.
     */
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the machine for the reading.
     */
    public function machine()
    {
        return $this->belongsTo(Machine::class);
    }

    /**
     * Get the categories for the reading.
     */
    public function categories()
    {
        return $this->hasMany(MachineReadingCategory::class, 'machine_readings_id');
    }

    /**
     * Scope for readings by date range
     */
    public function scopeByDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('reading_date', [$startDate, $endDate]);
    }

    /**
     * Scope for readings by machine
     */
    public function scopeByMachine($query, $machineId)
    {
        return $query->where('machine_id', $machineId);
    }

    /**
     * Scope for readings by type
     */
    public function scopeByType($query, $type)
    {
        return $query->where('reading_type', $type);
    }

}

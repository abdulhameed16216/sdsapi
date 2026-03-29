<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Attendance extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'attendance';

    protected $fillable = [
        'emp_id',
        'date',
        'in_time',
        'out_time',
        'customer_id',
        'selfie_image',
        'latitude',
        'longitude',
        'location',
        'punch_out_latitude',
        'punch_out_longitude',
        'punch_out_location',
        'type',
        'notes',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'date' => 'date',
        'in_time' => 'datetime:H:i:s',
        'out_time' => 'datetime:H:i:s',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'punch_out_latitude' => 'decimal:8',
        'punch_out_longitude' => 'decimal:8',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the employee for this attendance
     */
    public function employee()
    {
        return $this->belongsTo(Employee::class, 'emp_id');
    }

    /**
     * Get the customer for this attendance
     */
    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    /**
     * Get the user who created this attendance
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated this attendance
     */
    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Scope for regular attendance
     */
    public function scopeRegular($query)
    {
        return $query->where('type', 'regular');
    }

    /**
     * Scope for regularized attendance
     */
    public function scopeRegularized($query)
    {
        return $query->where('type', 'regularized');
    }

    /**
     * Scope for selfie attendance
     */
    public function scopeSelfie($query)
    {
        return $query->where('type', 'selfie');
    }

    /**
     * Scope for location-based attendance
     */
    public function scopeLocation($query)
    {
        return $query->where('type', 'location');
    }

    /**
     * Scope for manual regularization
     */
    public function scopeManualRegularization($query)
    {
        return $query->where('type', 'manual_regularization');
    }

    /**
     * Get type options
     */
    public static function getTypeOptions()
    {
        return [
            'regular' => 'Regular',
            'regularized' => 'Regularized',
            'selfie' => 'Selfie Attendance',
            'location' => 'Location Based',
            'manual_regularization' => 'Manual Regularization'
        ];
    }
}
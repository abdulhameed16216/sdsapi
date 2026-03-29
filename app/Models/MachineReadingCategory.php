<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MachineReadingCategory extends Model
{
    use HasFactory;

    protected $table = 'machine_readings_category';

    protected $fillable = [
        'machine_readings_id',
        'category',
        'reading_value',
        'notes',
    ];

    protected $casts = [
        'reading_value' => 'decimal:2',
    ];

    /**
     * Get the machine reading that owns this category.
     */
    public function machineReading()
    {
        return $this->belongsTo(MachineReading::class, 'machine_readings_id');
    }
}

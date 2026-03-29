<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeFloor extends Model
{
    use HasFactory;

    protected $table = 'employee_floor';

    protected $fillable = [
        'group_id',
        'location_id',
        'floor_id',
        'employee_id',
    ];

    public function customerGroup()
    {
        return $this->belongsTo(CustomerGroup::class, 'group_id');
    }

    public function location()
    {
        return $this->belongsTo(Customer::class, 'location_id');
    }

    public function floor()
    {
        return $this->belongsTo(CustomerFloor::class, 'floor_id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}


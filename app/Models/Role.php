<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Role extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'display_name',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Get the employees for the role.
     */
    public function employees()
    {
        return $this->hasMany(Employee::class);
    }

    /**
     * Get the users for the role through employees.
     */
    public function users()
    {
        return $this->hasManyThrough(User::class, Employee::class, 'role_id', 'employee_id');
    }


    /**
     * Get the privileges for the role.
     */
    public function privileges()
    {
        return $this->hasMany(RolePrivilege::class);
    }

    /**
     * Scope for active roles
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Get the count of users through employees
     */
    public function getUserCountAttribute()
    {
        return $this->users()->count();
    }

    /**
     * Get the count of employees
     */
    public function getEmployeeCountAttribute()
    {
        return $this->employees()->count();
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RolePrivilege extends Model
{
    use HasFactory;

    protected $fillable = [
        'role_id',
        'category',
        'action',
    ];

    /**
     * Get the role that owns the privilege.
     */
    public function role()
    {
        return $this->belongsTo(Role::class);
    }
}
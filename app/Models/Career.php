<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Career extends Model
{
    protected $fillable = [
        'full_name',
        'email',
        'mobile_number',
        'position_applied_for',
        'more_about_you',
        'resume',
        'status',
    ];

    protected $casts = [
        'resume' => 'array',
    ];
}


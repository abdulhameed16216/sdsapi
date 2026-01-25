<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerProgram extends Model
{
    protected $fillable = [
        'name',
        'short_desc',
        'list_items',
        'user_id',
    ];

    protected $casts = [
        'list_items' => 'array',
    ];

    /**
     * Get the user that owns the partner program.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

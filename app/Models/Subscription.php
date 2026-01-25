<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    protected $fillable = [
        'title',
        'short_description',
        'main_description',
        'start_price',
        'end_price',
        'price_unit',
        'sections',
        'status',
        'is_visible_on_website',
        'user_id',
    ];

    protected $casts = [
        'start_price' => 'decimal:2',
        'end_price' => 'decimal:2',
        'sections' => 'array',
        'status' => 'integer', // 0 = Draft, 1 = Published, 2 = Archived
    ];

    /**
     * Get the user that owns the subscription.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}


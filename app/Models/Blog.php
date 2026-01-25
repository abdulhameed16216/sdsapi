<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Blog extends Model
{
    protected $fillable = [
        'title',
        'content',
        'image',
        'slug',
        'category',
        'excerpt',
        'tags',
        'is_published',
        'user_id',
    ];

    protected $casts = [
        'is_published' => 'integer', // 0 = Draft, 1 = Published, 2 = Archived
    ];

    /**
     * Get the user that owns the blog.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

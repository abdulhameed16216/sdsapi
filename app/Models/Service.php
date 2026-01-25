<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Service extends Model
{
    protected $fillable = [
        'title',
        'content',
        'image',
        'slug',
        'excerpt',
        'icon',
        'parent_id',
        'is_published',
        'user_id',
    ];

    protected $casts = [
        'is_published' => 'integer', // 0 = Draft, 1 = Published, 2 = Archived
    ];

    /**
     * Get the user that owns the service.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the parent service (if this is a sub-service).
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'parent_id');
    }

    /**
     * Get the sub-services (children).
     */
    public function subServices(): HasMany
    {
        return $this->hasMany(Service::class, 'parent_id');
    }

    /**
     * Get the FAQs for this service.
     */
    public function faqs(): HasMany
    {
        return $this->hasMany(Faq::class);
    }
}


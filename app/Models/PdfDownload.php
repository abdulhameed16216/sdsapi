<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PdfDownload extends Model
{
    protected $fillable = [
        'title',
        'short_description',
        'file_name',
        'file_path',
        'file_url',
        'file_size',
        'user_id',
    ];

    /**
     * Get the user that uploaded the PDF.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}


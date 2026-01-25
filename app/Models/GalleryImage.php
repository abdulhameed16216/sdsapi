<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GalleryImage extends Model
{
    protected $fillable = [
        'title',
        'category',
        'filename',
        'file_path',
        'url',
        'mime_type',
        'file_size',
    ];
}

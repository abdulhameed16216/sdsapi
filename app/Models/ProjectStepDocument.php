<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectStepDocument extends Model
{
    protected $fillable = [
        'project_id',
        'project_step_id',
        'name',
        'file_name',
        'file_path',
        'file_url',
        'file_size',
        'mime_type',
    ];

    /**
     * Get the project that owns the document.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the project step that owns the document.
     */
    public function projectStep(): BelongsTo
    {
        return $this->belongsTo(ProjectStep::class);
    }

    /**
     * Get the file URL attribute - return public URL for files stored in public folder.
     */
    public function getFileUrlAttribute($value)
    {
        // Get file_name and file_path from attributes to avoid recursion
        $fileName = $this->attributes['file_name'] ?? null;
        $filePath = $this->attributes['file_path'] ?? null;
        
        // If we have a file_path, generate URL from it (files are in public/uploads/project/...)
        if ($filePath && str_contains($filePath, 'uploads/project/')) {
            $appUrl = rtrim(config('app.url'), '/');
            // Remove 'public' from path if present, as public folder is the web root
            $publicPath = str_replace('public/', '', $filePath);
            return $appUrl . '/' . $publicPath;
        }
        
        // Fallback: If URL doesn't exist or is invalid, generate from file_name
        if (empty($value) || !str_contains($value, '/uploads/project/')) {
            if ($fileName) {
                // Try to construct URL from file_path if available
                if ($filePath) {
                    $appUrl = rtrim(config('app.url'), '/');
                    $publicPath = str_replace('public/', '', $filePath);
                    return $appUrl . '/' . $publicPath;
                }
                // Last resort: use authenticated route (for old files)
                $appUrl = rtrim(config('app.url'), '/');
                return $appUrl . '/api/projects/documents/' . $fileName;
            }
        }
        return $value;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectStep extends Model
{
    protected $fillable = [
        'project_id',
        'step_id',
        'description',
        'status',
        'completion_date',
        'documents', // Keep for backward compatibility
    ];

    protected $casts = [
        'completion_date' => 'date',
        'documents' => 'array', // Keep for backward compatibility
    ];

    /**
     * Get the project that owns the step.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the documents for the project step.
     */
    public function stepDocuments(): HasMany
    {
        return $this->hasMany(ProjectStepDocument::class, 'project_step_id');
    }
}


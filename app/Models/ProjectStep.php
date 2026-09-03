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

    protected $appends = [
        'step_name',
    ];

    protected $casts = [
        'completion_date' => 'date',
        'documents' => 'array', // Keep for backward compatibility
    ];

    /**
     * Map step_id to the display title used in admin.
     */
    public function getStepNameAttribute(): ?string
    {
        return self::nameFor($this->step_id);
    }

    public static function names(): array
    {
        return ProjectStepMaster::names();
    }

    public static function nameFor($stepId): ?string
    {
        return self::names()[(int) $stepId] ?? null;
    }

    /**
     * Return all configured steps with titles from project_step_masters.
     * Existing DB steps are merged in; missing ones are placeholders.
     */
    public static function fullList($existingSteps = [], $projectId = null): array
    {
        $existing = collect($existingSteps)->keyBy(function ($step) {
            $stepId = is_array($step) ? ($step['step_id'] ?? null) : $step->step_id;

            return (int) $stepId;
        });

        $steps = [];
        foreach (self::names() as $stepId => $name) {
            $step = $existing->get($stepId);

            if ($step) {
                $data = $step instanceof Model ? $step->toArray() : (array) $step;
                $data['name'] = $name;
                $data['step_name'] = $name;
                $data['title'] = $name;

                if (isset($data['step_documents']) && !isset($data['stepDocuments'])) {
                    $data['stepDocuments'] = $data['step_documents'];
                } elseif (isset($data['stepDocuments']) && !isset($data['step_documents'])) {
                    $data['step_documents'] = $data['stepDocuments'];
                }

                $steps[] = $data;
                continue;
            }

            $steps[] = [
                'id' => null,
                'project_id' => $projectId,
                'step_id' => $stepId,
                'name' => $name,
                'step_name' => $name,
                'title' => $name,
                'description' => null,
                'status' => $stepId === 1 ? 'completed' : 'pending',
                'completion_date' => null,
                'documents' => [],
                'step_documents' => [],
                'stepDocuments' => [],
            ];
        }

        return $steps;
    }

    /**
     * Get the project that owns the step.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(ProjectStepMaster::class, 'step_id', 'step_id');
    }

    /**
     * Get the documents for the project step.
     */
    public function stepDocuments(): HasMany
    {
        return $this->hasMany(ProjectStepDocument::class, 'project_step_id');
    }
}


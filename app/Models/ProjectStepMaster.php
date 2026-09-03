<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectStepMaster extends Model
{
    protected static $cachedNames = null;
    protected $fillable = [
        'step_id',
        'name',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'step_id' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function projectSteps(): HasMany
    {
        return $this->hasMany(ProjectStep::class, 'step_id', 'step_id');
    }

    public static function activeOrdered()
    {
        return static::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('step_id');
    }

    public static function names(): array
    {
        if (self::$cachedNames !== null) {
            return self::$cachedNames;
        }

        try {
            $names = static::activeOrdered()->pluck('name', 'step_id')->all();
        } catch (\Throwable $e) {
            $names = [];
        }

        self::$cachedNames = $names;

        return self::$cachedNames;
    }

    public static function maxWorkStepId(): int
    {
        return (int) (static::query()
            ->where('is_active', true)
            ->where('step_id', '>', 1)
            ->max('step_id') ?: 0);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationWorkflowVersion extends Model
{
    protected $fillable = [
        'workflow_id',
        'version',
        'enrollment_policy',
        'definition',
        'checksum',
        'published_at',
    ];

    protected $casts = [
        'definition' => 'array',
        'version' => 'integer',
        'published_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Published automation versions are immutable.'));
        static::deleting(fn () => throw new \LogicException('Published automation versions cannot be deleted directly.'));
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(AutomationWorkflow::class, 'workflow_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class, 'workflow_version_id');
    }
}

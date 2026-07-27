<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationWorkflow extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'description',
        'status',
        'enrollment_policy',
        'trigger_type',
        'draft_definition',
        'revision',
        'active_version_id',
    ];

    protected $casts = [
        'draft_definition' => 'array',
        'revision' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function activeVersion(): BelongsTo
    {
        return $this->belongsTo(AutomationWorkflowVersion::class, 'active_version_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(AutomationWorkflowVersion::class, 'workflow_id')->latest('version');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class, 'workflow_id');
    }
}

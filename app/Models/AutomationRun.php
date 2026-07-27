<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationRun extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'workflow_id',
        'workflow_version_id',
        'automation_event_id',
        'contact_id',
        'funnel_id',
        'submission_id',
        'opportunity_id',
        'status',
        'current_node_id',
        'context',
        'started_at',
        'next_resume_at',
        'finished_at',
        'last_error',
    ];

    protected $casts = [
        'context' => 'array',
        'started_at' => 'datetime',
        'next_resume_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(AutomationWorkflow::class, 'workflow_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(AutomationWorkflowVersion::class, 'workflow_version_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(AutomationEvent::class, 'automation_event_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function funnel(): BelongsTo
    {
        return $this->belongsTo(Funnel::class);
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(ContactSubmission::class, 'submission_id');
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(AutomationStepRun::class, 'automation_run_id')->oldest();
    }
}

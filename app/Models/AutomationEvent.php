<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationEvent extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'event_type',
        'contact_id',
        'funnel_id',
        'submission_id',
        'opportunity_id',
        'payload',
        'causation_run_id',
        'causation_depth',
        'status',
        'attempts',
        'available_at',
        'processed_at',
        'last_error',
    ];

    protected $casts = [
        'payload' => 'array',
        'causation_depth' => 'integer',
        'attempts' => 'integer',
        'available_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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

    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class, 'automation_event_id');
    }
}

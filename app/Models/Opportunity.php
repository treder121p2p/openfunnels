<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Opportunity extends Model
{
    protected $fillable = [
        'user_id',
        'pipeline_id',
        'pipeline_stage_id',
        'contact_id',
        'funnel_id',
        'title',
        'value_cents',
        'status',
        'source',
        'expected_close_date',
        'stage_changed_at',
        'closed_at',
    ];

    protected $casts = [
        'value_cents' => 'integer',
        'expected_close_date' => 'date',
        'stage_changed_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'pipeline_stage_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function funnel(): BelongsTo
    {
        return $this->belongsTo(Funnel::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(OpportunityActivity::class)->latest();
    }

    public function recordActivity(string $type, array $payload = [], ?int $actorUserId = null): OpportunityActivity
    {
        return $this->activities()->create([
            'actor_user_id' => $actorUserId,
            'type' => $type,
            'payload' => $payload,
        ]);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FunnelOpportunitySetting extends Model
{
    protected $fillable = [
        'funnel_id',
        'enabled',
        'pipeline_id',
        'pipeline_stage_id',
        'default_value_cents',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'default_value_cents' => 'integer',
    ];

    public function funnel(): BelongsTo
    {
        return $this->belongsTo(Funnel::class);
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'pipeline_stage_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationStepRun extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'automation_run_id',
        'node_id',
        'node_type',
        'status',
        'attempt',
        'scheduled_for',
        'started_at',
        'finished_at',
        'input_summary',
        'output_summary',
        'error_code',
        'error_message',
        'idempotency_key',
    ];

    protected $casts = [
        'attempt' => 'integer',
        'scheduled_for' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'input_summary' => 'array',
        'output_summary' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(AutomationRun::class, 'automation_run_id');
    }
}

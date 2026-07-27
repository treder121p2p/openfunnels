<?php

namespace App\Jobs;

use App\Models\AutomationRun;
use App\Services\Automation\WorkflowRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class ExecuteAutomationRun implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [10, 60, 300, 900];

    public function __construct(public string $runId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping("automation-run:{$this->runId}"))->expireAfter(300)];
    }

    public function handle(WorkflowRunner $runner): void
    {
        $run = AutomationRun::find($this->runId);
        if ($run) {
            $runner->execute($run);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $run = AutomationRun::find($this->runId);
        if ($run && ! in_array($run->status, ['completed', 'cancelled'], true)) {
            $run->steps()
                ->where('node_id', $run->current_node_id)
                ->whereIn('status', ['queued', 'running'])
                ->update([
                    'status' => 'failed',
                    'finished_at' => now(),
                    'error_code' => 'retries_exhausted',
                    'error_message' => mb_substr($exception?->getMessage() ?? 'The workflow job exhausted its retries.', 0, 2000),
                ]);
            $run->update([
                'status' => 'failed',
                'last_error' => mb_substr($exception?->getMessage() ?? 'The workflow job exhausted its retries.', 0, 2000),
                'finished_at' => now(),
            ]);
        }
    }
}

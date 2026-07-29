<?php

namespace App\Jobs;

use App\Models\AutomationRun;
use App\Services\Automation\WorkflowRunner;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class ExecuteAutomationRun implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $uniqueFor = 600;

    public array $backoff = [10, 60, 300, 900];

    public function __construct(public string $runId) {}

    public function uniqueId(): string
    {
        return $this->runId;
    }

    public function middleware(): array
    {
        $staleAfter = max(1, (int) config('automation.stale_run_after_minutes', 5));

        return [(new WithoutOverlapping("automation-run:{$this->runId}"))
            ->releaseAfter(15)
            ->expireAfter($staleAfter * 60)];
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
                    'scheduled_for' => null,
                    'finished_at' => now(),
                    'error_code' => 'retries_exhausted',
                    'error_message' => mb_substr($exception?->getMessage() ?? 'The workflow job exhausted its retries.', 0, 2000),
                ]);
            $run->update([
                'status' => 'failed',
                'next_resume_at' => null,
                'last_error' => mb_substr($exception?->getMessage() ?? 'The workflow job exhausted its retries.', 0, 2000),
                'finished_at' => now(),
            ]);
        }
    }
}

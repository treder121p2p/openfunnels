<?php

namespace App\Services\Automation;

use App\Jobs\ExecuteAutomationRun;
use App\Models\AutomationRun;
use App\Models\AutomationStepRun;
use App\Services\Automation\Data\WorkflowExecutionContext;
use App\Services\Automation\Exceptions\PermanentAutomationException;
use App\Services\Automation\Exceptions\RetryableAutomationException;
use Illuminate\Support\Facades\DB;
use Throwable;

class WorkflowRunner
{
    public function __construct(private ActionRegistry $actions) {}

    public function execute(AutomationRun $run): void
    {
        $run->loadMissing(['version', 'user', 'event']);
        if (in_array($run->status, ['completed', 'failed', 'cancelled'], true)) {
            return;
        }

        if ($run->status === 'queued' && $run->next_resume_at?->isFuture()) {
            $this->dispatchAt($run, $run->next_resume_at);

            return;
        }

        $definition = $run->version?->definition ?? [];
        $node = collect($definition['nodes'] ?? [])->firstWhere('id', $run->current_node_id);
        if (! is_array($node)) {
            $this->failRun($run, 'missing_node', 'The current workflow step is missing.');

            return;
        }

        $step = AutomationStepRun::firstOrCreate(
            ['automation_run_id' => $run->id, 'node_id' => $node['id']],
            [
                'node_type' => $node['type'],
                'status' => 'queued',
                'idempotency_key' => hash('sha256', "{$run->id}:{$node['id']}"),
            ],
        );

        if ($step->status === 'waiting') {
            if ($step->scheduled_for?->isFuture()) {
                $this->dispatchAt($run, $step->scheduled_for);

                return;
            }

            $this->completeStep($run, $step, $node, ['wait_completed' => true]);

            return;
        }

        if (in_array($step->status, ['completed', 'suppressed'], true)) {
            $this->advanceFromCompletedStep($run, $step, $node);

            return;
        }

        $claimed = DB::transaction(function () use ($run, $step, $node): bool {
            $locked = AutomationRun::query()->lockForUpdate()->findOrFail($run->id);
            if (in_array($locked->status, ['completed', 'failed', 'cancelled'], true)) {
                return false;
            }

            $lockedStep = AutomationStepRun::query()->lockForUpdate()->findOrFail($step->id);
            $staleBefore = now()->subMinutes(max(1, (int) config('automation.stale_run_after_minutes', 5)));
            if ($lockedStep->status === 'running' && $lockedStep->updated_at?->isAfter($staleBefore)) {
                return false;
            }

            if (! in_array($lockedStep->status, ['queued', 'failed', 'running'], true)) {
                return false;
            }

            $lockedStep->update([
                'status' => 'running',
                'attempt' => $lockedStep->attempt + 1,
                'scheduled_for' => null,
                'started_at' => now(),
                'finished_at' => null,
                'error_code' => null,
                'error_message' => null,
                'input_summary' => ['type' => $node['type']],
            ]);
            $locked->update([
                'status' => 'running',
                'started_at' => $locked->started_at ?? now(),
                'next_resume_at' => null,
                'last_error' => null,
            ]);

            return true;
        });

        if (! $claimed) {
            return;
        }

        $run->refresh()->loadMissing(['version', 'user', 'event']);
        $step->refresh();
        $context = new WorkflowExecutionContext($run, $step);

        try {
            $result = $this->actions->get($node['type'])->execute($context, $node['config'] ?? []);
            $context->persistContext();

            if ($run->fresh()->status === 'cancelled') {
                $step->update(['status' => 'cancelled', 'finished_at' => now()]);

                return;
            }

            if ($result->status === 'waiting' && $result->resumeAt) {
                $step->update([
                    'status' => 'waiting',
                    'scheduled_for' => $result->resumeAt,
                    'output_summary' => $result->output,
                ]);
                $run->update([
                    'status' => 'waiting',
                    'next_resume_at' => $result->resumeAt,
                ]);
                $this->dispatchAt($run, $result->resumeAt);

                return;
            }

            $nextNodeId = $result->nextNodeId ?? ($node['next_node_id'] ?? null);
            $step->update([
                'status' => $result->status,
                'finished_at' => now(),
                'output_summary' => [
                    ...$result->output,
                    'next_node_id' => $nextNodeId,
                    'terminal' => $result->terminal,
                ],
            ]);

            if ($result->terminal || ! $nextNodeId) {
                $run->update([
                    'status' => 'completed',
                    'current_node_id' => null,
                    'next_resume_at' => null,
                    'finished_at' => now(),
                ]);

                return;
            }

            $run->update([
                'status' => 'queued',
                'current_node_id' => $nextNodeId,
                'next_resume_at' => null,
            ]);
            $this->dispatchNow($run);
        } catch (PermanentAutomationException $exception) {
            $step->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_code' => $exception->errorCode,
                'error_message' => mb_substr($exception->getMessage(), 0, 2000),
            ]);
            $this->failRun($run, $exception->errorCode, $exception->getMessage());
        } catch (RetryableAutomationException $exception) {
            $this->markRetryable($run, $step, $exception->errorCode, $exception->getMessage());
            throw $exception;
        } catch (Throwable $exception) {
            $this->markRetryable($run, $step, 'unexpected_error', $exception->getMessage());
            throw $exception;
        }
    }

    private function completeStep(AutomationRun $run, AutomationStepRun $step, array $node, array $output): void
    {
        $nextNodeId = $node['next_node_id'] ?? null;
        $step->update([
            'status' => 'completed',
            'finished_at' => now(),
            'output_summary' => [...$output, 'next_node_id' => $nextNodeId],
        ]);

        if (! $nextNodeId) {
            $run->update(['status' => 'completed', 'current_node_id' => null, 'next_resume_at' => null, 'finished_at' => now()]);

            return;
        }

        $run->update(['status' => 'queued', 'current_node_id' => $nextNodeId, 'next_resume_at' => null]);
        $this->dispatchNow($run);
    }

    private function advanceFromCompletedStep(AutomationRun $run, AutomationStepRun $step, array $node): void
    {
        $terminal = (bool) data_get($step->output_summary, 'terminal', false);
        $nextNodeId = data_get($step->output_summary, 'next_node_id', $node['next_node_id'] ?? null);

        if ($terminal || ! $nextNodeId) {
            $run->update(['status' => 'completed', 'current_node_id' => null, 'next_resume_at' => null, 'finished_at' => now()]);

            return;
        }

        $run->update(['status' => 'queued', 'current_node_id' => $nextNodeId, 'next_resume_at' => null]);
        $this->dispatchNow($run);
    }

    private function markRetryable(AutomationRun $run, AutomationStepRun $step, string $code, string $message): void
    {
        $retryAt = now()->addSeconds($this->retryDelaySeconds($step->attempt));
        $step->update([
            'status' => 'queued',
            'scheduled_for' => $retryAt,
            'error_code' => $code,
            'error_message' => mb_substr($message, 0, 2000),
        ]);
        $run->update([
            'status' => 'queued',
            'next_resume_at' => $retryAt,
            'last_error' => mb_substr($message, 0, 2000),
        ]);
    }

    private function failRun(AutomationRun $run, string $code, string $message): void
    {
        $run->update([
            'status' => 'failed',
            'next_resume_at' => null,
            'last_error' => "{$code}: ".mb_substr($message, 0, 1900),
            'finished_at' => now(),
        ]);
    }

    private function dispatchNow(AutomationRun $run): void
    {
        if (config('queue.default') === 'sync') {
            $this->execute($run->fresh());

            return;
        }

        ExecuteAutomationRun::dispatch($run->id)
            ->onQueue(config('automation.queue', 'default'))
            ->afterCommit();
    }

    private function dispatchAt(AutomationRun $run, \DateTimeInterface $time): void
    {
        if (config('queue.default') === 'sync') {
            return;
        }

        ExecuteAutomationRun::dispatch($run->id)
            ->delay($time)
            ->onQueue(config('automation.queue', 'default'))
            ->afterCommit();
    }

    private function retryDelaySeconds(int $attempt): int
    {
        $backoff = [10, 60, 300, 900];

        return $backoff[min(max($attempt, 1) - 1, count($backoff) - 1)];
    }
}

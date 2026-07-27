<?php

namespace App\Services\Automation;

use App\Jobs\ExecuteAutomationRun;
use App\Models\AutomationEvent;
use App\Models\AutomationRun;
use App\Models\AutomationWorkflow;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class WorkflowMatcher
{
    public function __construct(
        private WorkflowContextBuilder $contextBuilder,
        private TriggerMatcher $triggerMatcher,
    ) {}

    public function dispatch(AutomationEvent $event): int
    {
        if ($event->status === 'processed') {
            return 0;
        }

        $context = $this->contextBuilder->build($event);
        $workflows = AutomationWorkflow::query()
            ->where('user_id', $event->user_id)
            ->where('status', 'active')
            ->where('trigger_type', $event->event_type)
            ->whereNotNull('active_version_id')
            ->with('activeVersion')
            ->limit(config('automation.max_matches_per_event', 20) + 1)
            ->get();

        if ($workflows->count() > config('automation.max_matches_per_event', 20)) {
            report(new \RuntimeException("Automation event {$event->id} exceeded the workflow match limit."));
            $workflows = $workflows->take(config('automation.max_matches_per_event', 20));
        }

        $created = 0;
        foreach ($workflows as $workflow) {
            if (! $workflow->activeVersion || ! $this->triggerMatcher->matches($workflow->activeVersion->definition, $event, $context)) {
                continue;
            }

            if ($this->isSelfCaused($workflow, $event) || ! $this->allowsEnrollment($workflow, $event)) {
                continue;
            }

            try {
                $run = DB::transaction(function () use ($workflow, $event, $context) {
                    return AutomationRun::firstOrCreate(
                        [
                            'workflow_version_id' => $workflow->active_version_id,
                            'automation_event_id' => $event->id,
                        ],
                        [
                            'user_id' => $workflow->user_id,
                            'workflow_id' => $workflow->id,
                            'contact_id' => $event->contact_id,
                            'funnel_id' => $event->funnel_id,
                            'submission_id' => $event->submission_id,
                            'opportunity_id' => $event->opportunity_id,
                            'status' => 'queued',
                            'current_node_id' => data_get($workflow->activeVersion->definition, 'start_node_id'),
                            'context' => $context,
                        ],
                    );
                });
            } catch (QueryException) {
                continue;
            }

            if ($run->wasRecentlyCreated) {
                $created++;
                ExecuteAutomationRun::dispatch($run->id)
                    ->onQueue(config('automation.queue', 'default'))
                    ->afterCommit();
            }
        }

        $event->update([
            'status' => 'processed',
            'processed_at' => now(),
            'last_error' => null,
        ]);

        return $created;
    }

    private function allowsEnrollment(AutomationWorkflow $workflow, AutomationEvent $event): bool
    {
        if (! $event->contact_id || $workflow->enrollment_policy === 'every_event') {
            return true;
        }

        $runs = $workflow->runs()->where('contact_id', $event->contact_id);

        return match ($workflow->enrollment_policy) {
            'once_per_contact' => ! $runs->exists(),
            'after_completion' => ! $runs->whereIn('status', ['queued', 'running', 'waiting'])->exists(),
            default => true,
        };
    }

    private function isSelfCaused(AutomationWorkflow $workflow, AutomationEvent $event): bool
    {
        if (! $event->causation_run_id) {
            return false;
        }

        return AutomationRun::query()
            ->whereKey($event->causation_run_id)
            ->where('workflow_id', $workflow->id)
            ->exists();
    }
}

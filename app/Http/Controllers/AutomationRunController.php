<?php

namespace App\Http\Controllers;

use App\Jobs\ExecuteAutomationRun;
use App\Models\AutomationRun;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AutomationRunController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request)
    {
        $this->authorize('viewAny', AutomationRun::class);
        $filters = $request->validate([
            'workflow_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['queued', 'running', 'waiting', 'completed', 'failed', 'cancelled'])],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $runs = $request->user()->automationRuns()
            ->with(['workflow:id,name', 'contact:id,name,email'])
            ->when($filters['workflow_id'] ?? null, fn ($query, $id) => $query->where('workflow_id', $id))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['search'] ?? null, function ($query, $search): void {
                $query->whereHas('contact', fn ($contactQuery) => $contactQuery
                    ->where('name', 'like', '%'.trim($search).'%')
                    ->orWhere('email', 'like', '%'.trim($search).'%'));
            })
            ->latest()
            ->paginate(25)
            ->withQueryString()
            ->through(fn (AutomationRun $run) => $this->serializeRun($run));

        return Inertia::render('automations/runs', [
            'runs' => $runs,
            'workflows' => $request->user()->automationWorkflows()->orderBy('name')->get(['id', 'name']),
            'filters' => [
                'workflow_id' => isset($filters['workflow_id']) ? (string) $filters['workflow_id'] : '',
                'status' => $filters['status'] ?? '',
                'search' => $filters['search'] ?? '',
            ],
        ]);
    }

    public function show(AutomationRun $run)
    {
        $this->authorize('view', $run);
        $run->load([
            'workflow:id,name',
            'version:id,workflow_id,version,published_at',
            'contact:id,name,email',
            'funnel:id,name,slug',
            'opportunity:id,title',
            'event:id,event_type,payload,created_at',
            'steps',
        ]);

        return Inertia::render('automations/run-detail', [
            'run' => [
                ...$this->serializeRun($run),
                'retryable' => $run->status === 'failed'
                    && $run->steps->where('status', 'failed')->sortByDesc('created_at')->first()?->error_code === 'retries_exhausted',
                'workflow_version' => $run->version->version,
                'event' => [
                    'type' => $run->event->event_type,
                    'payload' => $run->event->payload ?? [],
                    'created_at' => $run->event->created_at->toISOString(),
                ],
                'funnel' => $run->funnel ? ['id' => $run->funnel->id, 'name' => $run->funnel->name] : null,
                'opportunity' => $run->opportunity ? ['id' => $run->opportunity->id, 'title' => $run->opportunity->title] : null,
                'steps' => $run->steps->map(fn ($step) => [
                    'id' => $step->id,
                    'node_id' => $step->node_id,
                    'node_type' => $step->node_type,
                    'status' => $step->status,
                    'attempt' => $step->attempt,
                    'scheduled_for' => $step->scheduled_for?->toISOString(),
                    'started_at' => $step->started_at?->toISOString(),
                    'finished_at' => $step->finished_at?->toISOString(),
                    'output' => $step->output_summary ?? [],
                    'error_code' => $step->error_code,
                    'error_message' => $step->error_message,
                ]),
            ],
        ]);
    }

    public function retry(AutomationRun $run)
    {
        $this->authorize('update', $run);
        abort_unless($run->status === 'failed', 422, 'Only failed workflow runs can be retried.');
        $step = $run->steps()->where('status', 'failed')->latest()->first();
        abort_unless($step, 422, 'No failed workflow step is available.');
        abort_unless($step->error_code === 'retries_exhausted', 422, 'This failure is permanent and cannot be retried safely.');

        $step->update([
            'status' => 'queued',
            'scheduled_for' => null,
            'finished_at' => null,
            'error_code' => null,
            'error_message' => null,
        ]);
        $run->update([
            'status' => 'queued',
            'current_node_id' => $step->node_id,
            'next_resume_at' => null,
            'finished_at' => null,
            'last_error' => null,
        ]);
        ExecuteAutomationRun::dispatch($run->id)->onQueue(config('automation.queue', 'default'));

        return back()->with('success', 'Workflow run queued for retry.');
    }

    public function cancel(AutomationRun $run)
    {
        $this->authorize('update', $run);
        abort_unless(in_array($run->status, ['queued', 'running', 'waiting'], true), 422, 'This workflow run is already finished.');
        $run->update(['status' => 'cancelled', 'finished_at' => now(), 'next_resume_at' => null]);
        $run->steps()->whereIn('status', ['queued', 'running', 'waiting'])->update([
            'status' => 'cancelled',
            'scheduled_for' => null,
            'finished_at' => now(),
        ]);

        return back()->with('success', 'Workflow run cancelled.');
    }

    private function serializeRun(AutomationRun $run): array
    {
        return [
            'id' => $run->id,
            'status' => $run->status,
            'current_node_id' => $run->current_node_id,
            'started_at' => $run->started_at?->toISOString(),
            'next_resume_at' => $run->next_resume_at?->toISOString(),
            'finished_at' => $run->finished_at?->toISOString(),
            'last_error' => $run->last_error,
            'created_at' => $run->created_at->toISOString(),
            'workflow' => ['id' => $run->workflow->id, 'name' => $run->workflow->name],
            'contact' => $run->contact ? [
                'id' => $run->contact->id,
                'name' => $run->contact->name,
                'email' => $run->contact->email,
            ] : null,
        ];
    }
}

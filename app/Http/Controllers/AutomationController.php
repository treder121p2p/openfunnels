<?php

namespace App\Http\Controllers;

use App\Models\AutomationWorkflow;
use App\Services\Automation\WebhookSigner;
use App\Services\Automation\WorkflowDefinitionValidator;
use App\Services\Automation\WorkflowPublisher;
use App\Services\Automation\WorkflowRecipes;
use App\Services\Automation\WorkflowSimulator;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AutomationController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request, WorkflowRecipes $recipes)
    {
        $this->authorize('viewAny', AutomationWorkflow::class);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['draft', 'active', 'paused', 'archived'])],
            'trigger' => ['nullable', 'string', 'max:100'],
        ]);

        $workflows = $request->user()->automationWorkflows()
            ->with('activeVersion:id,workflow_id,version,published_at')
            ->withCount([
                'runs as runs_last_7_days_count' => fn ($query) => $query->where('created_at', '>=', now()->subDays(7)),
                'runs as completed_runs_count' => fn ($query) => $query->where('status', 'completed'),
                'runs as failed_runs_count' => fn ($query) => $query->where('status', 'failed'),
            ])
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where('name', 'like', '%'.trim($search).'%'))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['trigger'] ?? null, fn ($query, $trigger) => $query->where('trigger_type', $trigger))
            ->when(! isset($filters['status']), fn ($query) => $query->where('status', '!=', 'archived'))
            ->withMax('runs as last_run_at', 'created_at')
            ->latest('updated_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (AutomationWorkflow $workflow) => $this->serializeWorkflow($workflow));

        return Inertia::render('automations/index', [
            'workflows' => $workflows,
            'recipes' => collect($recipes->all())->map(fn ($recipe, $key) => [
                'key' => $key,
                'name' => $recipe['name'],
                'description' => $recipe['description'],
            ])->values(),
            'filters' => [
                'search' => $filters['search'] ?? '',
                'status' => $filters['status'] ?? '',
                'trigger' => $filters['trigger'] ?? '',
            ],
        ]);
    }

    public function store(Request $request, WorkflowRecipes $recipes)
    {
        $this->authorize('create', AutomationWorkflow::class);
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'recipe' => ['nullable', 'string', Rule::in(array_keys($recipes->all()))],
            'enrollment_policy' => ['nullable', Rule::in(['once_per_contact', 'after_completion', 'every_event'])],
        ]);

        $recipe = $recipes->get($validated['recipe'] ?? null);
        $workflow = $request->user()->automationWorkflows()->create([
            'name' => trim($validated['name'] ?? '') ?: ($recipe['name'] ?? 'Untitled automation'),
            'description' => $validated['description'] ?? ($recipe['description'] ?? null),
            'status' => 'draft',
            'enrollment_policy' => $validated['enrollment_policy'] ?? 'every_event',
            'draft_definition' => $recipe['definition'] ?? $recipes->blank(),
            'revision' => 1,
        ]);

        return redirect()->route('automations.edit', $workflow)->with('success', 'Automation draft created.');
    }

    public function edit(Request $request, AutomationWorkflow $workflow, WebhookSigner $webhookSigner)
    {
        $this->authorize('update', $workflow);
        $workflow->load('activeVersion:id,workflow_id,version,published_at');

        return Inertia::render('automations/editor', [
            'workflow' => [
                'id' => $workflow->id,
                'name' => $workflow->name,
                'description' => $workflow->description,
                'status' => $workflow->status,
                'enrollment_policy' => $workflow->enrollment_policy,
                'revision' => $workflow->revision,
                'definition' => $workflow->draft_definition,
                'active_version' => $workflow->activeVersion ? [
                    'version' => $workflow->activeVersion->version,
                    'published_at' => $workflow->activeVersion->published_at?->toISOString(),
                ] : null,
            ],
            'options' => [
                'funnels' => $request->user()->funnels()->orderBy('name')->get(['id', 'name']),
                'pipelines' => $request->user()->pipelines()->with('stages:id,pipeline_id,name,position')->orderBy('name')->get(['id', 'name', 'currency']),
                'contacts' => $request->user()->contacts()->latest()->limit(100)->get(['id', 'name', 'email']),
                'webhook_signing_secret' => $webhookSigner->secretFor($workflow->id),
            ],
        ]);
    }

    public function autosave(
        Request $request,
        AutomationWorkflow $workflow,
        WorkflowDefinitionValidator $validator,
    ): JsonResponse {
        $this->authorize('update', $workflow);
        $validated = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'enrollment_policy' => ['required', Rule::in(['once_per_contact', 'after_completion', 'every_event'])],
            'definition' => ['required', 'array'],
        ]);

        $validator->assertValid($validated['definition'], $request->user());

        $updated = DB::transaction(function () use ($workflow, $validated) {
            $locked = AutomationWorkflow::query()->lockForUpdate()->findOrFail($workflow->id);
            if ($locked->revision !== (int) $validated['revision']) {
                return null;
            }

            $locked->update([
                'name' => trim($validated['name']),
                'description' => $validated['description'] ?? null,
                'enrollment_policy' => $validated['enrollment_policy'],
                'draft_definition' => $validated['definition'],
                'revision' => $locked->revision + 1,
            ]);

            return $locked;
        });

        if (! $updated) {
            return response()->json([
                'message' => 'This automation was updated elsewhere. Reload before saving again.',
                'revision' => $workflow->fresh()->revision,
            ], 409);
        }

        return response()->json([
            'message' => 'Draft saved.',
            'revision' => $updated->revision,
            'saved_at' => $updated->updated_at->toISOString(),
        ]);
    }

    public function validateDefinition(
        Request $request,
        AutomationWorkflow $workflow,
        WorkflowDefinitionValidator $validator,
    ): JsonResponse {
        $this->authorize('update', $workflow);
        $definition = $request->validate(['definition' => ['required', 'array']])['definition'];
        $errors = $validator->validate($definition, $request->user(), true);

        return response()->json(['valid' => $errors === [], 'errors' => $errors], $errors === [] ? 200 : 422);
    }

    public function simulate(
        Request $request,
        AutomationWorkflow $workflow,
        WorkflowSimulator $simulator,
    ): JsonResponse {
        $this->authorize('update', $workflow);
        $validated = $request->validate([
            'contact_id' => ['nullable', 'integer'],
            'opportunity_id' => ['nullable', 'integer'],
        ]);
        $contact = isset($validated['contact_id']) ? $request->user()->contacts()->findOrFail($validated['contact_id']) : null;
        $opportunity = isset($validated['opportunity_id']) ? $request->user()->opportunities()->findOrFail($validated['opportunity_id']) : null;
        $workflow->setRelation('user', $request->user());

        return response()->json(['path' => $simulator->simulate($workflow, $contact, $opportunity)]);
    }

    public function publish(Request $request, AutomationWorkflow $workflow, WorkflowPublisher $publisher)
    {
        $this->authorize('update', $workflow);
        $version = $publisher->publish($workflow);

        return back()->with('success', "Automation version {$version->version} published.");
    }

    public function pause(AutomationWorkflow $workflow)
    {
        $this->authorize('update', $workflow);
        abort_unless($workflow->active_version_id, 422, 'Publish this automation before pausing it.');
        $workflow->update(['status' => 'paused']);

        return back()->with('success', 'Automation paused. Existing runs will continue.');
    }

    public function resume(AutomationWorkflow $workflow)
    {
        $this->authorize('update', $workflow);
        abort_unless($workflow->active_version_id, 422, 'Publish this automation before activating it.');
        $workflow->update(['status' => 'active']);

        return back()->with('success', 'Automation resumed.');
    }

    public function duplicate(Request $request, AutomationWorkflow $workflow)
    {
        $this->authorize('view', $workflow);
        $copy = $request->user()->automationWorkflows()->create([
            'name' => $workflow->name.' copy',
            'description' => $workflow->description,
            'status' => 'draft',
            'enrollment_policy' => $workflow->enrollment_policy,
            'draft_definition' => $workflow->draft_definition,
            'revision' => 1,
        ]);

        return redirect()->route('automations.edit', $copy)->with('success', 'Automation duplicated as a draft.');
    }

    public function destroy(AutomationWorkflow $workflow)
    {
        $this->authorize('delete', $workflow);

        if ($workflow->runs()->exists()) {
            $workflow->update(['status' => 'archived']);
        } else {
            $workflow->delete();
        }

        return redirect()->route('automations.index')->with('success', 'Automation archived.');
    }

    private function serializeWorkflow(AutomationWorkflow $workflow): array
    {
        return [
            'id' => $workflow->id,
            'name' => $workflow->name,
            'description' => $workflow->description,
            'status' => $workflow->status,
            'enrollment_policy' => $workflow->enrollment_policy,
            'trigger_type' => $workflow->trigger_type ?? data_get($workflow->draft_definition, 'trigger.type'),
            'revision' => $workflow->revision,
            'active_version' => $workflow->activeVersion?->version,
            'runs_last_7_days' => $workflow->runs_last_7_days_count,
            'completed_runs' => $workflow->completed_runs_count,
            'failed_runs' => $workflow->failed_runs_count,
            'last_run_at' => $workflow->last_run_at ? Carbon::parse($workflow->last_run_at)->toISOString() : null,
            'updated_at' => $workflow->updated_at->toISOString(),
        ];
    }
}

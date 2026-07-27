<?php

namespace App\Http\Controllers;

use App\Models\Opportunity;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Services\Automation\AutomationEventRecorder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class OpportunityController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request)
    {
        $this->authorize('viewAny', Opportunity::class);

        $filters = $request->validate([
            'pipeline_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'in:open,won,lost,all'],
            'search' => ['nullable', 'string', 'max:100'],
            'create_for_contact' => ['nullable', 'integer'],
        ]);

        $pipelines = $request->user()
            ->pipelines()
            ->with('stages')
            ->orderBy('name')
            ->get();

        $pipeline = isset($filters['pipeline_id'])
            ? $pipelines->firstWhere('id', (int) $filters['pipeline_id'])
            : $pipelines->first();
        $status = $filters['status'] ?? 'open';
        $search = trim($filters['search'] ?? '');

        $opportunities = collect();
        $stats = [
            'open_value_cents' => 0,
            'weighted_value_cents' => 0,
            'won_value_cents' => 0,
        ];

        if ($pipeline) {
            $query = $request->user()
                ->opportunities()
                ->where('pipeline_id', $pipeline->id)
                ->with([
                    'contact:id,name,email,phone',
                    'funnel:id,name,slug',
                    'stage:id,name,probability',
                    'activities' => fn ($activityQuery) => $activityQuery->with('actor:id,name')->latest()->limit(20),
                ])
                ->when($status !== 'all', fn (Builder $builder) => $builder->where('status', $status))
                ->when($search, function (Builder $builder) use ($search): void {
                    $builder->where(function (Builder $searchQuery) use ($search): void {
                        $searchQuery
                            ->where('title', 'like', "%{$search}%")
                            ->orWhereHas('contact', function (Builder $contactQuery) use ($search): void {
                                $contactQuery
                                    ->where('name', 'like', "%{$search}%")
                                    ->orWhere('email', 'like', "%{$search}%")
                                    ->orWhere('phone', 'like', "%{$search}%");
                            });
                    });
                })
                ->latest('stage_changed_at')
                ->latest();

            $opportunities = $query->get()->map(fn (Opportunity $opportunity) => $this->serializeOpportunity($opportunity));

            $openOpportunities = $request->user()
                ->opportunities()
                ->where('pipeline_id', $pipeline->id)
                ->where('status', 'open')
                ->with('stage:id,probability')
                ->get(['id', 'pipeline_stage_id', 'value_cents']);

            $stats = [
                'open_value_cents' => $openOpportunities->sum('value_cents'),
                'weighted_value_cents' => (int) round($openOpportunities->sum(
                    fn (Opportunity $opportunity) => $opportunity->value_cents * (($opportunity->stage?->probability ?? 0) / 100)
                )),
                'won_value_cents' => (int) $request->user()
                    ->opportunities()
                    ->where('pipeline_id', $pipeline->id)
                    ->where('status', 'won')
                    ->sum('value_cents'),
            ];
        }

        $preselectedContact = isset($filters['create_for_contact'])
            ? $request->user()->contacts()->find($filters['create_for_contact'])
            : null;

        return Inertia::render('opportunities', [
            'pipelines' => $pipelines->map(fn (Pipeline $item) => [
                'id' => $item->id,
                'name' => $item->name,
                'currency' => $item->currency,
                'stages' => $item->stages->map(fn (PipelineStage $stage) => [
                    'id' => $stage->id,
                    'name' => $stage->name,
                    'position' => $stage->position,
                    'probability' => $stage->probability,
                ]),
            ]),
            'selectedPipelineId' => $pipeline?->id,
            'opportunities' => $opportunities,
            'stats' => $stats,
            'filters' => [
                'status' => $status,
                'search' => $search,
            ],
            'preselectedContact' => $preselectedContact ? [
                'id' => $preselectedContact->id,
                'name' => $preselectedContact->name,
                'email' => $preselectedContact->email,
                'phone' => $preselectedContact->phone,
            ] : null,
        ]);
    }

    public function store(Request $request, AutomationEventRecorder $automationEvents)
    {
        $this->authorize('create', Opportunity::class);

        $validated = $request->validate([
            'contact_id' => ['required', 'integer'],
            'pipeline_id' => ['required', 'integer'],
            'pipeline_stage_id' => ['required', 'integer'],
            'funnel_id' => ['nullable', 'integer'],
            'title' => ['nullable', 'string', 'max:255'],
            'value' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'expected_close_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $contact = $request->user()->contacts()->findOrFail($validated['contact_id']);
        $pipeline = $request->user()->pipelines()->findOrFail($validated['pipeline_id']);
        $stage = $pipeline->stages()->findOrFail($validated['pipeline_stage_id']);
        $funnel = isset($validated['funnel_id'])
            ? $request->user()->funnels()->findOrFail($validated['funnel_id'])
            : null;

        $opportunity = DB::transaction(function () use ($request, $validated, $contact, $pipeline, $stage, $funnel, $automationEvents) {
            $opportunity = $request->user()->opportunities()->create([
                'pipeline_id' => $pipeline->id,
                'pipeline_stage_id' => $stage->id,
                'contact_id' => $contact->id,
                'funnel_id' => $funnel?->id,
                'title' => trim($validated['title'] ?? '') ?: ($contact->name ?: $contact->email),
                'value_cents' => $this->valueToCents($validated['value'] ?? 0),
                'status' => 'open',
                'source' => $funnel ? 'funnel' : 'manual',
                'expected_close_date' => $validated['expected_close_date'] ?? null,
                'stage_changed_at' => now(),
            ]);

            $opportunity->recordActivity('created', [
                'stage_id' => $stage->id,
                'stage_name' => $stage->name,
            ], $request->user()->id);
            $automationEvents->record(
                $request->user(),
                'opportunity.created',
                contact: $contact,
                funnel: $funnel,
                opportunity: $opportunity,
                payload: ['source' => $opportunity->source],
            );

            return $opportunity;
        });

        return back()->with('success', "Opportunity {$opportunity->title} created.");
    }

    public function update(Request $request, Opportunity $opportunity, AutomationEventRecorder $automationEvents)
    {
        $this->authorize('update', $opportunity);

        $validated = $request->validate([
            'pipeline_stage_id' => ['sometimes', 'required', 'integer'],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'value' => ['sometimes', 'required', 'numeric', 'min:0', 'max:99999999.99'],
            'status' => ['sometimes', 'required', 'string', 'in:open,won,lost'],
            'expected_close_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);

        $stage = isset($validated['pipeline_stage_id'])
            ? $opportunity->pipeline->stages()->findOrFail($validated['pipeline_stage_id'])
            : null;

        DB::transaction(function () use ($request, $validated, $opportunity, $stage, $automationEvents): void {
            $stageChange = null;
            $statusChange = null;

            if ($stage && $stage->id !== $opportunity->pipeline_stage_id) {
                $previousStage = $opportunity->stage;
                $opportunity->pipeline_stage_id = $stage->id;
                $opportunity->stage_changed_at = now();
                $opportunity->recordActivity('stage_moved', [
                    'from_stage_id' => $previousStage?->id,
                    'from_stage_name' => $previousStage?->name,
                    'to_stage_id' => $stage->id,
                    'to_stage_name' => $stage->name,
                ], $request->user()->id);
                $stageChange = [
                    'from_stage_id' => $previousStage?->id,
                    'to_stage_id' => $stage->id,
                ];
            }

            if (isset($validated['status']) && $validated['status'] !== $opportunity->status) {
                $previousStatus = $opportunity->status;
                $opportunity->status = $validated['status'];
                $opportunity->closed_at = $validated['status'] === 'open' ? null : now();
                $opportunity->recordActivity('status_changed', [
                    'from' => $previousStatus,
                    'to' => $validated['status'],
                ], $request->user()->id);
                $statusChange = [
                    'from_status' => $previousStatus,
                    'to_status' => $validated['status'],
                ];
            }

            if (array_key_exists('value', $validated)) {
                $valueCents = $this->valueToCents($validated['value']);

                if ($valueCents !== $opportunity->value_cents) {
                    $opportunity->recordActivity('value_changed', [
                        'from_cents' => $opportunity->value_cents,
                        'to_cents' => $valueCents,
                    ], $request->user()->id);
                    $opportunity->value_cents = $valueCents;
                }
            }

            if (isset($validated['title'])) {
                $opportunity->title = trim($validated['title']);
            }

            if (array_key_exists('expected_close_date', $validated)) {
                $opportunity->expected_close_date = $validated['expected_close_date'];
            }

            $opportunity->save();

            if ($stageChange) {
                $automationEvents->record(
                    $request->user(),
                    'opportunity.stage_changed',
                    contact: $opportunity->contact,
                    funnel: $opportunity->funnel,
                    opportunity: $opportunity,
                    payload: $stageChange,
                );
            }

            if ($statusChange) {
                $automationEvents->record(
                    $request->user(),
                    'opportunity.status_changed',
                    contact: $opportunity->contact,
                    funnel: $opportunity->funnel,
                    opportunity: $opportunity,
                    payload: $statusChange,
                );
            }
        });

        return back()->with('success', 'Opportunity updated.');
    }

    public function destroy(Opportunity $opportunity)
    {
        $this->authorize('delete', $opportunity);
        $opportunity->delete();

        return back()->with('success', 'Opportunity removed.');
    }

    private function valueToCents(mixed $value): int
    {
        return (int) round((float) $value * 100);
    }

    private function serializeOpportunity(Opportunity $opportunity): array
    {
        return [
            'id' => $opportunity->id,
            'title' => $opportunity->title,
            'value_cents' => $opportunity->value_cents,
            'status' => $opportunity->status,
            'source' => $opportunity->source,
            'expected_close_date' => $opportunity->expected_close_date?->format('Y-m-d'),
            'stage_changed_at' => $opportunity->stage_changed_at?->toISOString(),
            'closed_at' => $opportunity->closed_at?->toISOString(),
            'created_at' => $opportunity->created_at->toISOString(),
            'stage' => [
                'id' => $opportunity->stage->id,
                'name' => $opportunity->stage->name,
                'probability' => $opportunity->stage->probability,
            ],
            'contact' => [
                'id' => $opportunity->contact->id,
                'name' => $opportunity->contact->name,
                'email' => $opportunity->contact->email,
                'phone' => $opportunity->contact->phone,
            ],
            'funnel' => $opportunity->funnel ? [
                'id' => $opportunity->funnel->id,
                'name' => $opportunity->funnel->name,
                'slug' => $opportunity->funnel->slug,
            ] : null,
            'activities' => $opportunity->activities->map(fn ($activity) => [
                'id' => $activity->id,
                'type' => $activity->type,
                'payload' => $activity->payload ?? [],
                'created_at' => $activity->created_at->toISOString(),
                'actor' => $activity->actor?->name,
            ]),
        ];
    }
}

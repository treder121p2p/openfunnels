<?php

namespace App\Services\Automation\Actions;

use App\Services\Automation\AutomationEventRecorder;
use App\Services\Automation\Contracts\WorkflowAction;
use App\Services\Automation\Data\ActionResult;
use App\Services\Automation\Data\WorkflowExecutionContext;
use App\Services\Automation\MergeFieldResolver;
use Illuminate\Support\Facades\DB;

class CreateOpportunityAction implements WorkflowAction
{
    public function __construct(
        private AutomationEventRecorder $events,
        private MergeFieldResolver $mergeFields,
    ) {}

    public function type(): string
    {
        return 'create_opportunity';
    }

    public function execute(WorkflowExecutionContext $context, array $config): ActionResult
    {
        $contact = $context->contact();
        $pipeline = $context->pipeline((int) $config['pipeline_id']);
        $stage = $context->stage($pipeline, (int) $config['stage_id']);
        $source = 'automation:'.$context->step->id;

        $opportunity = DB::transaction(function () use ($context, $config, $contact, $pipeline, $stage, $source) {
            $existing = $context->owner()->opportunities()->where('source', $source)->first();
            if ($existing) {
                return $existing;
            }

            $opportunity = $context->owner()->opportunities()->create([
                'pipeline_id' => $pipeline->id,
                'pipeline_stage_id' => $stage->id,
                'contact_id' => $contact->id,
                'funnel_id' => $context->run->funnel_id,
                'title' => trim($this->mergeFields->render((string) ($config['title'] ?? '{{ contact.name }}'), $context)) ?: ($contact->name ?: $contact->email),
                'value_cents' => (int) round(((float) ($config['value'] ?? 0)) * 100),
                'status' => 'open',
                'source' => $source,
                'stage_changed_at' => now(),
            ]);

            $opportunity->recordActivity('created', [
                'source' => 'workflow',
                'workflow_id' => $context->run->workflow_id,
                'stage_id' => $stage->id,
                'stage_name' => $stage->name,
            ]);

            $this->events->record(
                $context->owner(),
                'opportunity.created',
                contact: $contact,
                funnel: $context->funnel(),
                opportunity: $opportunity,
                payload: ['source' => 'workflow'],
                causedBy: $context->run,
            );

            return $opportunity;
        });

        $context->run->opportunity_id = $opportunity->id;
        $context->set('opportunity', [
            'id' => $opportunity->id,
            'title' => $opportunity->title,
            'value' => $opportunity->value_cents / 100,
            'value_cents' => $opportunity->value_cents,
            'status' => $opportunity->status,
            'source' => $opportunity->source,
        ]);
        $context->set('pipeline', ['id' => $pipeline->id, 'name' => $pipeline->name, 'currency' => $pipeline->currency]);
        $context->set('stage', ['id' => $stage->id, 'name' => $stage->name, 'probability' => $stage->probability]);

        return ActionResult::completed([
            'opportunity_id' => $opportunity->id,
            'pipeline' => $pipeline->name,
            'stage' => $stage->name,
        ]);
    }

    public function simulate(WorkflowExecutionContext $context, array $config): ActionResult
    {
        return ActionResult::completed([
            'would_create_opportunity' => true,
            'pipeline_id' => $config['pipeline_id'] ?? null,
            'stage_id' => $config['stage_id'] ?? null,
            'value' => $config['value'] ?? 0,
        ]);
    }
}

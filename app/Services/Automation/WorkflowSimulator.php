<?php

namespace App\Services\Automation;

use App\Models\AutomationRun;
use App\Models\AutomationStepRun;
use App\Models\AutomationWorkflow;
use App\Models\AutomationWorkflowVersion;
use App\Models\Contact;
use App\Models\Opportunity;
use App\Services\Automation\Data\WorkflowExecutionContext;
use Illuminate\Support\Str;

class WorkflowSimulator
{
    public function __construct(
        private WorkflowDefinitionValidator $validator,
        private ActionRegistry $actions,
    ) {}

    public function simulate(AutomationWorkflow $workflow, ?Contact $contact, ?Opportunity $opportunity): array
    {
        $definition = $workflow->draft_definition;
        $this->validator->assertValid($definition, $workflow->user);

        $context = [
            'event' => [
                'id' => 'simulation',
                'type' => data_get($definition, 'trigger.type'),
                'occurred_at' => now()->toISOString(),
                'payload' => [],
            ],
            'contact' => $contact ? [
                'id' => $contact->id,
                'name' => $contact->name,
                'email' => $contact->email,
                'phone' => $contact->phone,
                'status' => $contact->status,
                'source' => $contact->source,
                'tags' => $contact->tags ?? [],
            ] : null,
            'funnel' => $contact?->funnel ? [
                'id' => $contact->funnel->id,
                'name' => $contact->funnel->name,
                'slug' => $contact->funnel->slug,
            ] : null,
            'submission' => null,
            'opportunity' => $opportunity ? [
                'id' => $opportunity->id,
                'title' => $opportunity->title,
                'value' => $opportunity->value_cents / 100,
                'value_cents' => $opportunity->value_cents,
                'status' => $opportunity->status,
                'source' => $opportunity->source,
            ] : null,
            'pipeline' => $opportunity?->pipeline ? [
                'id' => $opportunity->pipeline->id,
                'name' => $opportunity->pipeline->name,
                'currency' => $opportunity->pipeline->currency,
            ] : null,
            'stage' => $opportunity?->stage ? [
                'id' => $opportunity->stage->id,
                'name' => $opportunity->stage->name,
                'probability' => $opportunity->stage->probability,
            ] : null,
        ];

        $version = new AutomationWorkflowVersion(['definition' => $definition]);
        $run = new AutomationRun([
            'id' => (string) Str::ulid(),
            'user_id' => $workflow->user_id,
            'workflow_id' => $workflow->id,
            'contact_id' => $contact?->id,
            'opportunity_id' => $opportunity?->id,
            'context' => $context,
        ]);
        $run->setRelation('user', $workflow->user);
        $run->setRelation('version', $version);

        $nodes = collect($definition['nodes'])->keyBy('id');
        $nodeId = $definition['start_node_id'];
        $path = [];

        for ($iteration = 0; $iteration < config('automation.max_nodes', 50); $iteration++) {
            $node = $nodes->get($nodeId);
            if (! is_array($node)) {
                break;
            }

            $step = new AutomationStepRun([
                'id' => (string) Str::ulid(),
                'node_id' => $node['id'],
                'node_type' => $node['type'],
                'idempotency_key' => 'simulation',
            ]);
            $executionContext = new WorkflowExecutionContext($run, $step);
            $result = $this->actions->get($node['type'])->simulate($executionContext, $node['config'] ?? []);
            $path[] = [
                'node_id' => $node['id'],
                'node_type' => $node['type'],
                'status' => 'simulated',
                'output' => $result->output,
            ];

            if ($result->terminal || $node['type'] === 'end') {
                break;
            }

            $nodeId = $result->nextNodeId ?? ($node['next_node_id'] ?? null);
            if (! $nodeId) {
                break;
            }
        }

        return $path;
    }
}

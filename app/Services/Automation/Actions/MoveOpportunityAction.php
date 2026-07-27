<?php

namespace App\Services\Automation\Actions;

use App\Services\Automation\AutomationEventRecorder;
use App\Services\Automation\Contracts\WorkflowAction;
use App\Services\Automation\Data\ActionResult;
use App\Services\Automation\Data\WorkflowExecutionContext;
use App\Services\Automation\Exceptions\PermanentAutomationException;
use Illuminate\Support\Facades\DB;

class MoveOpportunityAction implements WorkflowAction
{
    public function __construct(private AutomationEventRecorder $events) {}

    public function type(): string
    {
        return 'move_opportunity';
    }

    public function execute(WorkflowExecutionContext $context, array $config): ActionResult
    {
        $pipeline = $context->pipeline((int) $config['pipeline_id']);
        $stage = $context->stage($pipeline, (int) $config['stage_id']);
        $opportunity = $context->opportunity()
            ?? $context->owner()->opportunities()
                ->where('contact_id', $context->contact()->id)
                ->where('pipeline_id', $pipeline->id)
                ->where('status', 'open')
                ->latest()
                ->first();

        if (! $opportunity || $opportunity->pipeline_id !== $pipeline->id) {
            throw new PermanentAutomationException('No matching open opportunity was found.', 'missing_opportunity');
        }

        $previousStage = $opportunity->stage;
        if ($previousStage?->id === $stage->id) {
            return ActionResult::completed(['opportunity_id' => $opportunity->id, 'unchanged' => true]);
        }

        DB::transaction(function () use ($context, $opportunity, $previousStage, $stage): void {
            $opportunity->update([
                'pipeline_stage_id' => $stage->id,
                'stage_changed_at' => now(),
            ]);
            $opportunity->recordActivity('stage_moved', [
                'from_stage_id' => $previousStage?->id,
                'from_stage_name' => $previousStage?->name,
                'to_stage_id' => $stage->id,
                'to_stage_name' => $stage->name,
                'source' => 'workflow',
            ]);
            $this->events->record(
                $context->owner(),
                'opportunity.stage_changed',
                contact: $opportunity->contact,
                funnel: $opportunity->funnel,
                opportunity: $opportunity,
                payload: [
                    'from_stage_id' => $previousStage?->id,
                    'to_stage_id' => $stage->id,
                ],
                causedBy: $context->run,
            );
        });

        $context->run->opportunity_id = $opportunity->id;
        $context->set('stage', ['id' => $stage->id, 'name' => $stage->name, 'probability' => $stage->probability]);

        return ActionResult::completed([
            'opportunity_id' => $opportunity->id,
            'from_stage' => $previousStage?->name,
            'to_stage' => $stage->name,
        ]);
    }

    public function simulate(WorkflowExecutionContext $context, array $config): ActionResult
    {
        return ActionResult::completed([
            'would_move_opportunity' => true,
            'pipeline_id' => $config['pipeline_id'] ?? null,
            'stage_id' => $config['stage_id'] ?? null,
        ]);
    }
}

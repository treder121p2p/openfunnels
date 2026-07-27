<?php

namespace App\Services\Automation\Actions;

use App\Services\Automation\Contracts\WorkflowAction;
use App\Services\Automation\Data\ActionResult;
use App\Services\Automation\Data\WorkflowExecutionContext;
use App\Services\Automation\TriggerMatcher;

class ConditionAction implements WorkflowAction
{
    public function __construct(private TriggerMatcher $matcher) {}

    public function type(): string
    {
        return 'condition';
    }

    public function execute(WorkflowExecutionContext $context, array $config): ActionResult
    {
        $matched = $this->matcher->compare(
            $context->get((string) ($config['field'] ?? '')),
            (string) ($config['operator'] ?? ''),
            $config['value'] ?? null,
        );

        $node = $context->run->version->definition['nodes'] ?? [];
        $current = collect($node)->firstWhere('id', $context->step->node_id) ?? [];

        return ActionResult::completed(
            ['matched' => $matched],
            $matched ? ($current['yes_node_id'] ?? null) : ($current['no_node_id'] ?? null),
        );
    }

    public function simulate(WorkflowExecutionContext $context, array $config): ActionResult
    {
        return $this->execute($context, $config);
    }
}

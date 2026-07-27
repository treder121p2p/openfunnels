<?php

namespace App\Services\Automation\Actions;

use App\Services\Automation\Contracts\WorkflowAction;
use App\Services\Automation\Data\ActionResult;
use App\Services\Automation\Data\WorkflowExecutionContext;

class EndAction implements WorkflowAction
{
    public function type(): string
    {
        return 'end';
    }

    public function execute(WorkflowExecutionContext $context, array $config): ActionResult
    {
        return ActionResult::completed(['ended' => true], terminal: true);
    }

    public function simulate(WorkflowExecutionContext $context, array $config): ActionResult
    {
        return $this->execute($context, $config);
    }
}

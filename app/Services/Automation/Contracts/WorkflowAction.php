<?php

namespace App\Services\Automation\Contracts;

use App\Services\Automation\Data\ActionResult;
use App\Services\Automation\Data\WorkflowExecutionContext;

interface WorkflowAction
{
    public function type(): string;

    public function execute(WorkflowExecutionContext $context, array $config): ActionResult;

    public function simulate(WorkflowExecutionContext $context, array $config): ActionResult;
}

<?php

namespace App\Services\Automation\Actions;

use App\Services\Automation\Contracts\WorkflowAction;
use App\Services\Automation\Data\ActionResult;
use App\Services\Automation\Data\WorkflowExecutionContext;

class WaitAction implements WorkflowAction
{
    public function type(): string
    {
        return 'wait';
    }

    public function execute(WorkflowExecutionContext $context, array $config): ActionResult
    {
        $amount = max(1, (int) ($config['amount'] ?? 1));
        $resumeAt = match ($config['unit'] ?? 'minutes') {
            'days' => now()->addDays($amount),
            'hours' => now()->addHours($amount),
            default => now()->addMinutes($amount),
        };

        return ActionResult::waiting($resumeAt);
    }

    public function simulate(WorkflowExecutionContext $context, array $config): ActionResult
    {
        return ActionResult::completed([
            'would_wait' => (int) ($config['amount'] ?? 1),
            'unit' => $config['unit'] ?? 'minutes',
        ]);
    }
}

<?php

namespace App\Services\Automation;

use App\Services\Automation\Actions\ConditionAction;
use App\Services\Automation\Actions\CreateOpportunityAction;
use App\Services\Automation\Actions\EndAction;
use App\Services\Automation\Actions\MoveOpportunityAction;
use App\Services\Automation\Actions\NotifyOwnerAction;
use App\Services\Automation\Actions\SendEmailAction;
use App\Services\Automation\Actions\UpdateContactAction;
use App\Services\Automation\Actions\WaitAction;
use App\Services\Automation\Actions\WebhookAction;
use App\Services\Automation\Contracts\WorkflowAction;
use InvalidArgumentException;

class ActionRegistry
{
    /** @var array<string, WorkflowAction> */
    private array $actions;

    public function __construct(
        EndAction $end,
        WaitAction $wait,
        ConditionAction $condition,
        UpdateContactAction $updateContact,
        CreateOpportunityAction $createOpportunity,
        MoveOpportunityAction $moveOpportunity,
        SendEmailAction $sendEmail,
        NotifyOwnerAction $notifyOwner,
        WebhookAction $webhook,
    ) {
        $this->actions = collect([
            $end,
            $wait,
            $condition,
            $updateContact,
            $createOpportunity,
            $moveOpportunity,
            $sendEmail,
            $notifyOwner,
            $webhook,
        ])->mapWithKeys(fn (WorkflowAction $action) => [$action->type() => $action])->all();
    }

    public function get(string $type): WorkflowAction
    {
        return $this->actions[$type] ?? throw new InvalidArgumentException("Unknown workflow action {$type}.");
    }
}

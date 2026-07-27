<?php

namespace App\Services\Automation\Actions;

use App\Mail\AutomationMessage;
use App\Services\Automation\Contracts\WorkflowAction;
use App\Services\Automation\Data\ActionResult;
use App\Services\Automation\Data\WorkflowExecutionContext;
use App\Services\Automation\MergeFieldResolver;
use Illuminate\Support\Facades\Mail;

class NotifyOwnerAction implements WorkflowAction
{
    public function __construct(private MergeFieldResolver $mergeFields) {}

    public function type(): string
    {
        return 'notify_owner';
    }

    public function execute(WorkflowExecutionContext $context, array $config): ActionResult
    {
        if ($context->owner()->is_demo) {
            return ActionResult::suppressed('External email is disabled in the guest sandbox.');
        }

        $subject = $this->mergeFields->render((string) $config['subject'], $context);
        $body = $this->mergeFields->render((string) $config['body'], $context);
        Mail::to($context->owner()->email)->send(new AutomationMessage(mb_substr($subject, 0, 255), $body));

        return ActionResult::completed([
            'recipient' => 'account owner',
            'subject' => mb_substr($subject, 0, 255),
        ]);
    }

    public function simulate(WorkflowExecutionContext $context, array $config): ActionResult
    {
        return ActionResult::completed([
            'would_notify_owner' => true,
            'subject' => $this->mergeFields->render((string) ($config['subject'] ?? ''), $context),
        ]);
    }
}

<?php

namespace App\Services\Automation\Actions;

use App\Mail\AutomationMessage;
use App\Models\ContactEmailPreference;
use App\Services\Automation\Contracts\WorkflowAction;
use App\Services\Automation\Data\ActionResult;
use App\Services\Automation\Data\WorkflowExecutionContext;
use App\Services\Automation\MergeFieldResolver;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

class SendEmailAction implements WorkflowAction
{
    public function __construct(private MergeFieldResolver $mergeFields) {}

    public function type(): string
    {
        return 'send_email';
    }

    public function execute(WorkflowExecutionContext $context, array $config): ActionResult
    {
        if ($context->owner()->is_demo) {
            return ActionResult::suppressed('External email is disabled in the guest sandbox.');
        }

        $contact = $context->contact();
        $preference = ContactEmailPreference::firstOrCreate(
            ['user_id' => $context->owner()->id, 'contact_id' => $contact->id],
            ['status' => 'unknown', 'source' => 'automation'],
        );

        $purpose = $config['purpose'] ?? 'marketing';
        if ($preference->status === 'bounced' || ($purpose === 'marketing' && $preference->status === 'unsubscribed')) {
            return ActionResult::suppressed("Email suppressed because the contact is {$preference->status}.");
        }

        $unsubscribeUrl = URL::temporarySignedRoute(
            'email.unsubscribe.show',
            now()->addYears(5),
            ['preference' => $preference->id],
        );
        $additional = ['unsubscribe_url' => $unsubscribeUrl];
        $subject = $this->mergeFields->render((string) $config['subject'], $context, $additional);
        $body = $this->mergeFields->render((string) $config['body'], $context, $additional);

        if ($purpose === 'marketing' && ! str_contains($body, $unsubscribeUrl)) {
            $body .= "\n\nUnsubscribe: {$unsubscribeUrl}";
        }

        Mail::to($contact->email)->send(new AutomationMessage(
            mb_substr($subject, 0, 255),
            $body,
            $config['reply_to'] ?? null,
        ));

        return ActionResult::completed([
            'recipient' => $this->maskEmail($contact->email),
            'purpose' => $purpose,
            'subject' => mb_substr($subject, 0, 255),
        ]);
    }

    public function simulate(WorkflowExecutionContext $context, array $config): ActionResult
    {
        return ActionResult::completed([
            'would_send_email' => true,
            'purpose' => $config['purpose'] ?? 'marketing',
            'subject' => $this->mergeFields->render((string) ($config['subject'] ?? ''), $context, ['unsubscribe_url' => '[unsubscribe link]']),
        ]);
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 2).'***@'.$domain;
    }
}

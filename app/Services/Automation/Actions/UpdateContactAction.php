<?php

namespace App\Services\Automation\Actions;

use App\Services\Automation\AutomationEventRecorder;
use App\Services\Automation\Contracts\WorkflowAction;
use App\Services\Automation\Data\ActionResult;
use App\Services\Automation\Data\WorkflowExecutionContext;
use Illuminate\Support\Facades\DB;

class UpdateContactAction implements WorkflowAction
{
    public function __construct(private AutomationEventRecorder $events) {}

    public function type(): string
    {
        return 'update_contact';
    }

    public function execute(WorkflowExecutionContext $context, array $config): ActionResult
    {
        $contact = $context->contact();
        $beforeStatus = $contact->status;
        $tags = collect($contact->tags ?? [])
            ->merge($config['add_tags'] ?? [])
            ->reject(fn ($tag) => in_array($tag, $config['remove_tags'] ?? [], true))
            ->map(fn ($tag) => trim((string) $tag))
            ->filter()
            ->unique()
            ->take(50)
            ->values()
            ->all();

        DB::transaction(function () use ($context, $contact, $config, $tags, $beforeStatus): void {
            $contact->update([
                'status' => $config['status'] ?? $contact->status,
                'tags' => $tags,
            ]);

            if ($contact->status !== $beforeStatus) {
                $this->events->record(
                    $context->owner(),
                    'contact.status_changed',
                    contact: $contact,
                    funnel: $contact->funnel,
                    payload: ['from_status' => $beforeStatus, 'to_status' => $contact->status],
                    causedBy: $context->run,
                );
            }
        });

        $context->set('contact.status', $contact->status);
        $context->set('contact.tags', $tags);

        return ActionResult::completed([
            'status' => $contact->status,
            'tags' => $tags,
        ]);
    }

    public function simulate(WorkflowExecutionContext $context, array $config): ActionResult
    {
        return ActionResult::completed([
            'would_update_status' => $config['status'] ?? null,
            'would_add_tags' => $config['add_tags'] ?? [],
            'would_remove_tags' => $config['remove_tags'] ?? [],
        ]);
    }
}

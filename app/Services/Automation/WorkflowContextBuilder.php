<?php

namespace App\Services\Automation;

use App\Models\AutomationEvent;

class WorkflowContextBuilder
{
    public function build(AutomationEvent $event): array
    {
        $event->loadMissing([
            'contact',
            'funnel',
            'submission',
            'opportunity.pipeline',
            'opportunity.stage',
        ]);

        return [
            'event' => [
                'id' => $event->id,
                'type' => $event->event_type,
                'occurred_at' => $event->created_at?->toISOString(),
                'payload' => $event->payload ?? [],
            ],
            'contact' => $event->contact ? [
                'id' => $event->contact->id,
                'name' => $event->contact->name,
                'email' => $event->contact->email,
                'phone' => $event->contact->phone,
                'status' => $event->contact->status,
                'source' => $event->contact->source,
                'tags' => $event->contact->tags ?? [],
            ] : null,
            'funnel' => $event->funnel ? [
                'id' => $event->funnel->id,
                'name' => $event->funnel->name,
                'slug' => $event->funnel->slug,
            ] : null,
            'submission' => $event->submission ? [
                'id' => $event->submission->id,
                'form_id' => $event->submission->form_id,
                'fields' => $event->submission->fields ?? [],
                'attribution' => $event->submission->attribution ?? [],
            ] : null,
            'opportunity' => $event->opportunity ? [
                'id' => $event->opportunity->id,
                'title' => $event->opportunity->title,
                'value' => $event->opportunity->value_cents / 100,
                'value_cents' => $event->opportunity->value_cents,
                'status' => $event->opportunity->status,
                'source' => $event->opportunity->source,
            ] : null,
            'pipeline' => $event->opportunity?->pipeline ? [
                'id' => $event->opportunity->pipeline->id,
                'name' => $event->opportunity->pipeline->name,
                'currency' => $event->opportunity->pipeline->currency,
            ] : null,
            'stage' => $event->opportunity?->stage ? [
                'id' => $event->opportunity->stage->id,
                'name' => $event->opportunity->stage->name,
                'probability' => $event->opportunity->stage->probability,
            ] : null,
        ];
    }
}

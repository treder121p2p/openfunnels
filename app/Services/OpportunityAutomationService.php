<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Funnel;
use App\Models\Opportunity;
use App\Services\Automation\AutomationEventRecorder;
use Throwable;

class OpportunityAutomationService
{
    public function __construct(private AutomationEventRecorder $automationEvents) {}

    public function createFromSubmission(Contact $contact, Funnel $funnel): ?Opportunity
    {
        try {
            $setting = $funnel->opportunitySetting()
                ->with(['pipeline', 'stage'])
                ->first();

            if (
                ! $setting?->enabled
                || ! $setting->pipeline
                || ! $setting->stage
                || $setting->pipeline->user_id !== $funnel->user_id
                || $setting->stage->pipeline_id !== $setting->pipeline_id
                || $contact->user_id !== $funnel->user_id
            ) {
                return null;
            }

            $existing = Opportunity::query()
                ->where('user_id', $funnel->user_id)
                ->where('contact_id', $contact->id)
                ->where('funnel_id', $funnel->id)
                ->where('pipeline_id', $setting->pipeline_id)
                ->where('status', 'open')
                ->first();

            if ($existing) {
                return $existing;
            }

            $opportunity = Opportunity::create([
                'user_id' => $funnel->user_id,
                'pipeline_id' => $setting->pipeline_id,
                'pipeline_stage_id' => $setting->pipeline_stage_id,
                'contact_id' => $contact->id,
                'funnel_id' => $funnel->id,
                'title' => ($contact->name ?: $contact->email).' — '.$funnel->name,
                'value_cents' => $setting->default_value_cents,
                'status' => 'open',
                'source' => 'funnel_form',
                'stage_changed_at' => now(),
            ]);

            $opportunity->recordActivity('created', [
                'source' => 'funnel_automation',
                'stage_id' => $setting->pipeline_stage_id,
            ]);
            $this->automationEvents->record(
                $funnel->user,
                'opportunity.created',
                contact: $contact,
                funnel: $funnel,
                opportunity: $opportunity,
                payload: ['source' => 'funnel_automation'],
            );

            return $opportunity;
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }
}

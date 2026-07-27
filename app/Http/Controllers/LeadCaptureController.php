<?php

namespace App\Http\Controllers;

use App\Jobs\SendLeadCaptureNotifications;
use App\Models\Contact;
use App\Models\ContactEmailPreference;
use App\Models\Funnel;
use App\Services\Automation\AutomationEventRecorder;
use App\Services\OpportunityAutomationService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class LeadCaptureController extends Controller
{
    use AuthorizesRequests;

    public function store(
        Request $request,
        Funnel $funnel,
        OpportunityAutomationService $opportunityAutomation,
        AutomationEventRecorder $automationEvents,
    ) {
        if (! $funnel->is_published) {
            $this->authorize('update', $funnel);
        }

        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'form_id' => ['nullable', 'string', 'max:100'],
            'fields' => ['nullable', 'array', 'max:50'],
            'fields.*' => ['nullable', 'string', 'max:5000'],
            'attribution' => ['nullable', 'array'],
            'attribution.utm_source' => ['nullable', 'string', 'max:255'],
            'attribution.utm_medium' => ['nullable', 'string', 'max:255'],
            'attribution.utm_campaign' => ['nullable', 'string', 'max:255'],
            'attribution.utm_term' => ['nullable', 'string', 'max:255'],
            'attribution.utm_content' => ['nullable', 'string', 'max:255'],
            'attribution.referrer' => ['nullable', 'url', 'max:2000'],
            'session_id' => ['nullable', 'uuid'],
            'variant_id' => ['nullable', 'integer'],
        ]);

        if (isset($validated['variant_id']) && ! $funnel->variants()->whereKey($validated['variant_id'])->exists()) {
            abort(422, 'Invalid experiment variant.');
        }

        [$contact, $submission] = DB::transaction(function () use (
            $request,
            $validated,
            $funnel,
            $opportunityAutomation,
            $automationEvents,
        ) {
            $email = strtolower($validated['email']);
            $contact = Contact::firstOrNew([
                'user_id' => $funnel->user_id,
                'email' => $email,
            ]);
            $isNewContact = ! $contact->exists;
            $metadata = $contact->metadata ?? [];
            $submissionCount = (int) data_get($metadata, 'submission_count', 0) + 1;

            $contact->fill([
                'funnel_id' => $funnel->id,
                'email' => $email,
                'name' => $validated['name'] ?? $contact->name,
                'phone' => $validated['phone'] ?? $contact->phone,
                'source' => 'funnel_form',
                'status' => $contact->status ?: 'new',
                'metadata' => [
                    ...$metadata,
                    'submission_count' => $submissionCount,
                    'last_form_id' => $validated['form_id'] ?? null,
                    'last_fields' => $validated['fields'] ?? [],
                    'last_url' => $request->headers->get('referer'),
                    'last_attribution' => $validated['attribution'] ?? [],
                ],
                'ip_address' => $request->ip(),
                'user_agent' => (string) $request->userAgent(),
                'last_submitted_at' => now(),
            ]);
            $contact->save();

            $submission = $contact->submissions()->create([
                'funnel_id' => $funnel->id,
                'variant_id' => $validated['variant_id'] ?? null,
                'form_id' => $validated['form_id'] ?? null,
                'fields' => $validated['fields'] ?? [],
                'attribution' => $validated['attribution'] ?? [],
                'source' => 'funnel_form',
                'url' => $request->headers->get('referer'),
                'ip_address' => $request->ip(),
                'user_agent' => (string) $request->userAgent(),
            ]);

            if ($this->isAffirmative(data_get($validated, 'fields.marketing_consent'))) {
                ContactEmailPreference::updateOrCreate(
                    ['user_id' => $funnel->user_id, 'contact_id' => $contact->id],
                    ['status' => 'subscribed', 'source' => 'funnel_form', 'consented_at' => now(), 'unsubscribed_at' => null],
                );
            }

            $funnel->incrementConversions();
            $funnel->events()->create([
                'event_type' => 'conversion',
                'variant_id' => $validated['variant_id'] ?? null,
                'session_id' => isset($validated['session_id']) ? hash('sha256', $validated['session_id']) : null,
                'form_id' => $validated['form_id'] ?? null,
                'attribution' => $validated['attribution'] ?? [],
                'occurred_at' => now(),
            ]);

            if ($isNewContact) {
                $automationEvents->record(
                    $funnel->user,
                    'contact.created',
                    contact: $contact,
                    funnel: $funnel,
                    payload: ['source' => 'funnel_form'],
                );
            }

            $opportunityAutomation->createFromSubmission($contact, $funnel);
            $automationEvents->record(
                $funnel->user,
                'funnel.form_submitted',
                contact: $contact,
                funnel: $funnel,
                submission: $submission,
                payload: [
                    'is_new_contact' => $isNewContact,
                    'submission_count' => $submissionCount,
                ],
            );

            return [$contact, $submission];
        });

        try {
            SendLeadCaptureNotifications::dispatch($contact->id, $funnel->id, $submission->id)
                ->onQueue(config('automation.queue', 'default'));
        } catch (Throwable $exception) {
            report($exception);
        }

        return back()->with('success', 'Thanks. Your information was submitted.');
    }

    private function isAffirmative(mixed $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on', 'subscribed'], true);
    }
}

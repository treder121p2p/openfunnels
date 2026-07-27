<?php

namespace App\Jobs;

use App\Mail\NewLeadCaptured;
use App\Models\Contact;
use App\Models\ContactSubmission;
use App\Models\Funnel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

class SendLeadCaptureNotifications implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(
        public int $contactId,
        public int $funnelId,
        public int $submissionId,
    ) {}

    public function handle(): void
    {
        $contact = Contact::find($this->contactId);
        $funnel = Funnel::with('user')->find($this->funnelId);
        $submission = ContactSubmission::find($this->submissionId);

        if (! $contact || ! $funnel || ! $submission || $funnel->user->is_demo) {
            return;
        }

        $recipient = config('services.lead_capture.notification_email') ?: $funnel->user->email;

        if ($recipient) {
            Mail::to($recipient)->send(new NewLeadCaptured($contact, $funnel, $submission));
        }

        $webhookUrl = config('services.lead_capture.webhook_url');
        if ($webhookUrl) {
            Http::timeout(5)->post($webhookUrl, [
                'event' => 'lead.captured',
                'contact' => [
                    'id' => $contact->id,
                    'email' => $contact->email,
                    'name' => $contact->name,
                    'phone' => $contact->phone,
                    'status' => $contact->status,
                ],
                'funnel' => [
                    'id' => $funnel->id,
                    'name' => $funnel->name,
                    'slug' => $funnel->slug,
                ],
                'submission' => [
                    'id' => $submission->id,
                    'form_id' => $submission->form_id,
                    'fields' => $submission->fields,
                    'url' => $submission->url,
                    'created_at' => $submission->created_at?->toISOString(),
                ],
            ]);
        }
    }
}

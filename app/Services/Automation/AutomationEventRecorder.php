<?php

namespace App\Services\Automation;

use App\Jobs\DispatchAutomationEvent;
use App\Models\AutomationEvent;
use App\Models\AutomationRun;
use App\Models\Contact;
use App\Models\ContactSubmission;
use App\Models\Funnel;
use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class AutomationEventRecorder
{
    public function record(
        User $owner,
        string $eventType,
        ?Contact $contact = null,
        ?Funnel $funnel = null,
        ?ContactSubmission $submission = null,
        ?Opportunity $opportunity = null,
        array $payload = [],
        ?AutomationRun $causedBy = null,
    ): ?AutomationEvent {
        $depth = $causedBy ? ((int) ($causedBy->event?->causation_depth ?? 0)) + 1 : 0;

        if ($depth > config('automation.max_causation_depth', 5)) {
            report(new \RuntimeException("Automation event {$eventType} was dropped at causation depth {$depth}."));

            return null;
        }

        $availableAt = $this->availableAtFor($owner);
        $event = AutomationEvent::create([
            'user_id' => $owner->id,
            'event_type' => $eventType,
            'contact_id' => $contact?->id,
            'funnel_id' => $funnel?->id,
            'submission_id' => $submission?->id,
            'opportunity_id' => $opportunity?->id,
            'payload' => $this->sanitizePayload($payload),
            'causation_run_id' => $causedBy?->id,
            'causation_depth' => $depth,
            'status' => 'pending',
            'available_at' => $availableAt,
        ]);

        DB::afterCommit(function () use ($event, $availableAt): void {
            try {
                DispatchAutomationEvent::dispatch($event->id)
                    ->onQueue(config('automation.queue', 'default'))
                    ->delay($availableAt);
            } catch (Throwable $exception) {
                $event->newQuery()->whereKey($event->id)->update([
                    'status' => 'pending',
                    'last_error' => mb_substr($exception->getMessage(), 0, 2000),
                    'available_at' => now()->addMinute(),
                ]);
                report($exception);
            }
        });

        return $event;
    }

    private function availableAtFor(User $owner): Carbon
    {
        $limit = max(1, (int) config('automation.event_daily_limit', 10000));
        $eventsToday = AutomationEvent::query()
            ->where('user_id', $owner->id)
            ->where('created_at', '>=', now()->startOfDay())
            ->count();

        if ($eventsToday < $limit) {
            return now();
        }

        $delayMinutes = min(60, intdiv($eventsToday - $limit, 100) + 1);

        return now()->addMinutes($delayMinutes);
    }

    private function sanitizePayload(array $payload): array
    {
        return collect($payload)
            ->except(['ip_address', 'user_agent', 'headers', 'authorization', 'password', 'secret'])
            ->map(function (mixed $value): mixed {
                if (is_string($value)) {
                    return mb_substr($value, 0, 5000);
                }

                if (is_array($value)) {
                    return array_slice($value, 0, 50, true);
                }

                return $value;
            })
            ->all();
    }
}

<?php

namespace App\Jobs;

use App\Models\AutomationEvent;
use App\Services\Automation\WorkflowMatcher;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class DispatchAutomationEvent implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $uniqueFor = 600;

    public array $backoff = [10, 60, 300, 900];

    public function __construct(public string $eventId) {}

    public function uniqueId(): string
    {
        return $this->eventId;
    }

    public function handle(WorkflowMatcher $matcher): void
    {
        $event = AutomationEvent::find($this->eventId);
        if (! $event || $event->status === 'processed') {
            return;
        }

        $event->update([
            'status' => 'dispatching',
            'attempts' => $event->attempts + 1,
        ]);

        try {
            $matcher->dispatch($event);
        } catch (Throwable $exception) {
            $event->update([
                'status' => $this->attempts() >= $this->tries ? 'failed' : 'pending',
                'last_error' => mb_substr($exception->getMessage(), 0, 2000),
                'available_at' => now()->addSeconds(60),
            ]);

            throw $exception;
        }
    }
}

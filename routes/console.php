<?php

use App\Jobs\DispatchAutomationEvent;
use App\Jobs\ExecuteAutomationRun;
use App\Models\AutomationEvent;
use App\Models\AutomationRun;
use App\Models\AutomationStepRun;
use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(fn () => User::query()
    ->where('is_demo', true)
    ->where('demo_expires_at', '<=', now())
    ->eachById(fn (User $user) => $user->delete()))
    ->hourly()
    ->name('cleanup-expired-demo-users')
    ->withoutOverlapping();

Schedule::call(fn () => AutomationEvent::query()
    ->whereIn('status', ['pending', 'dispatching'])
    ->where('available_at', '<=', now())
    ->where(fn ($query) => $query->whereNull('updated_at')->orWhere('updated_at', '<=', now()->subMinutes(2)))
    ->limit(500)
    ->get('id')
    ->each(fn (AutomationEvent $event) => DispatchAutomationEvent::dispatch($event->id)
        ->onQueue(config('automation.queue', 'default'))))
    ->everyMinute()
    ->name('recover-pending-automation-events')
    ->withoutOverlapping();

Schedule::call(fn () => AutomationRun::query()
    ->where('status', 'waiting')
    ->where('next_resume_at', '<=', now())
    ->limit(500)
    ->get('id')
    ->each(fn (AutomationRun $run) => ExecuteAutomationRun::dispatch($run->id)
        ->onQueue(config('automation.queue', 'default'))))
    ->everyMinute()
    ->name('recover-waiting-automation-runs')
    ->withoutOverlapping();

Schedule::call(fn () => AutomationRun::query()
    ->where('status', 'queued')
    ->where(fn ($query) => $query->whereNull('next_resume_at')->orWhere('next_resume_at', '<=', now()))
    ->where('updated_at', '<=', now()->subMinutes(2))
    ->limit(500)
    ->get('id')
    ->each(fn (AutomationRun $run) => ExecuteAutomationRun::dispatch($run->id)
        ->onQueue(config('automation.queue', 'default'))))
    ->everyMinute()
    ->name('recover-queued-automation-runs')
    ->withoutOverlapping();

Schedule::call(function (): void {
    $staleBefore = now()->subMinutes(max(1, (int) config('automation.stale_run_after_minutes', 5)));

    AutomationRun::query()
        ->where('status', 'running')
        ->where('updated_at', '<=', $staleBefore)
        ->limit(500)
        ->get()
        ->each(function (AutomationRun $run) use ($staleBefore): void {
            $recovered = DB::transaction(function () use ($run, $staleBefore): bool {
                $locked = AutomationRun::query()->lockForUpdate()->find($run->id);
                if (! $locked || $locked->status !== 'running' || $locked->updated_at?->isAfter($staleBefore)) {
                    return false;
                }

                $locked->steps()
                    ->where('node_id', $locked->current_node_id)
                    ->where('status', 'running')
                    ->update(['status' => 'queued']);
                $locked->update(['status' => 'queued']);

                return true;
            });

            if ($recovered) {
                ExecuteAutomationRun::dispatch($run->id)
                    ->onQueue(config('automation.queue', 'default'));
            }
        });
})
    ->everyMinute()
    ->name('recover-stale-automation-runs')
    ->withoutOverlapping();

Schedule::call(function (): void {
    $cutoff = now()->subDays(config('automation.run_retention_days', 90));
    AutomationEvent::query()
        ->where('status', 'processed')
        ->where('processed_at', '<', $cutoff)
        ->whereNotNull('payload')
        ->update(['payload' => null, 'last_error' => null]);
    AutomationStepRun::query()
        ->where('finished_at', '<', $cutoff)
        ->update(['input_summary' => null, 'output_summary' => null, 'error_message' => null]);
    AutomationRun::query()
        ->where('finished_at', '<', $cutoff)
        ->update(['context' => '[]', 'last_error' => null]);
})->daily()->name('prune-automation-run-details')->withoutOverlapping();

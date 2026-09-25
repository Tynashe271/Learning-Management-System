<?php

use App\Models\Assignment;
use App\Models\User;
use App\Notifications\AssignmentDueSoon;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function (): void {
    if (! config('lms.notifications.due_reminders')) {
        return;
    }
    // "N days ahead" means the whole calendar day N days from now in the institution's time zone.
    $tz = config('lms.institution.timezone');
    $day = now($tz)->addDays(max(1, (int) config('lms.notifications.due_reminder_days')));
    Assignment::query()->where('published', true)
        ->whereBetween('due_at', [$day->copy()->startOfDay()->utc(), $day->copy()->endOfDay()->utc()])
        ->with('offering')->chunkById(100, function ($assignments): void {
            foreach ($assignments as $assignment) {
                if (! $assignment->offering->published) {
                    continue;
                }
                $userIds = $assignment->offering->enrolments()->where('status', 'active')->pluck('user_id');
                User::whereIn('id', $userIds)->chunkById(100, function ($users) use ($assignment): void {
                    foreach ($users as $user) {
                        $user->notify(AssignmentDueSoon::fromAssignment($assignment));
                    }
                });
            }
        });
})->name('assignment-due-reminders')->dailyAt('08:00')->timezone(config('lms.institution.timezone'))->withoutOverlapping();

// A pulse the health endpoint reads: if it stops, the scheduler container is down or stuck.
Schedule::call(fn () => Cache::put('lms:scheduler:heartbeat', time(), 900))->name('scheduler-heartbeat')->everyMinute();

// Summary emails for people who opted in; times are UTC.
Schedule::command('lms:send-digests daily')->dailyAt('07:00')->timezone('UTC')->withoutOverlapping();
Schedule::command('lms:send-digests weekly')->weeklyOn(1, '07:00')->timezone('UTC')->withoutOverlapping();

// Nightly housekeeping: remove expired records, then (if switched on) back everything up. Times are in the institution's time zone.
Schedule::command('lms:prune')->dailyAt('03:15')->timezone(config('lms.institution.timezone'))->withoutOverlapping();
Schedule::command('lms:backup')->dailyAt('02:30')->timezone(config('lms.institution.timezone'))->withoutOverlapping(180)->when(fn () => (bool) config('lms.backups.enabled'));

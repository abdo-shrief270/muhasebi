<?php

use App\Jobs\CleanupJob;
use App\Jobs\GenerateSitemapJob;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Queue Worker
|--------------------------------------------------------------------------
|
| Email and other notifications are dispatched via Redis queues.
| To process queued jobs, run:
|
|   php artisan queue:work redis --queue=default,emails,reports,webhooks,maintenance
|
| In production, use a process supervisor (Supervisor, systemd, or
| Laravel Horizon) to keep the worker running.
|
| Queue priorities:
|   default    — standard operations
|   emails     — email dispatch (rate-limited)
|   reports    — heavy report generation
|   webhooks   — webhook delivery + retries
|   maintenance — sitemap, cleanup, backups
|
*/

// Record daily usage for all tenants (runs at midnight)
Schedule::command('usage:record')->dailyAt('00:05');

// Daily database backup at 2am (keep 30 days)
Schedule::command('backup:database --keep=30')->dailyAt('02:00');

// Clean up old API request logs (runs daily at 2:30am)
Schedule::command('api-logs:clean --days=30')->dailyAt('02:30');

// Clean up old auth tokens (runs daily at 3am)
Schedule::command('tokens:cleanup --days=30')->dailyAt('03:00');

// Clean up stale push notification device tokens (weekly)
Schedule::call(fn () => app(\App\Domain\Notification\Services\PushNotificationService::class)->cleanupStaleTokens(90))
    ->weekly()->sundays()->at('04:30');

// Daily cleanup job — API logs, temp files, old webhook deliveries (queued)
Schedule::job(new CleanupJob)->dailyAt('03:30');

// Regenerate sitemap (queued to avoid blocking scheduler)
Schedule::job(new GenerateSitemapJob)->dailyAt('04:00');

// Publish scheduled blog posts (runs every 5 minutes)
Schedule::command('blog:publish-scheduled')->everyFiveMinutes();

// Process subscription lifecycle (expiry, grace period, suspension)
Schedule::command('subscriptions:lifecycle')->hourly();

// Generate recurring invoices (runs daily at 6am)
Schedule::command('invoices:process-recurring')->dailyAt('06:00');

// Send invoice payment reminders (runs daily at 9am)
Schedule::command('invoices:send-reminders')->dailyAt('09:00');

// Send aging reminders for overdue invoices (30/60/90 days - runs daily at 10am)
Schedule::command('invoices:aging-reminders')->dailyAt('10:00');

// Sync pending payment statuses with gateways (catch missed webhooks)
Schedule::command('payments:sync-status --hours=48')->everyThirtyMinutes();

// Flush remaining API usage buffers at end of day
Schedule::command('usage:flush-buffers')->dailyAt('23:55');

// Purge soft-deleted tenants after 30-day grace period (weekly, with auto-export)
Schedule::command('tenants:purge --export --force')->weekly()->sundays()->at('05:00');

// Fetch exchange rates (daily at 7am, only if enabled)
if (config('currency.auto_fetch', false)) {
    Schedule::command('currency:fetch-rates')->dailyAt('07:00');
}

// ETA: Auto-check status for submitted documents (every 30 minutes)
Schedule::command('eta:check-status --hours=48')->everyThirtyMinutes();

// ETA: Daily reconciliation with ETA API (3am)
Schedule::command('eta:reconcile')->dailyAt('03:00');

// Retry webhook deliveries stuck in "retrying" state (hourly)
Schedule::command('webhooks:retry-stuck')->hourly();

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Webhook\Models\WebhookDelivery;
use App\Jobs\DispatchWebhookJob;
use Illuminate\Console\Command;

class RetryStuckWebhooksCommand extends Command
{
    protected $signature = 'webhooks:retry-stuck {--hours=4 : Hours threshold for stuck deliveries}';

    protected $description = 'Retry webhook deliveries stuck in pending/retrying status';

    public function handle(): int
    {
        $threshold = now()->subHours((int) $this->option('hours'));

        $stuck = WebhookDelivery::whereIn('status', ['pending', 'retrying'])
            ->where('updated_at', '<', $threshold)
            ->get();

        if ($stuck->isEmpty()) {
            $this->info('No stuck webhook deliveries found.');

            return 0;
        }

        $this->warn("Found {$stuck->count()} stuck deliveries.");

        foreach ($stuck as $delivery) {
            try {
                DispatchWebhookJob::dispatch($delivery);
                $this->line("  Re-queued delivery #{$delivery->id}");
            } catch (\Throwable $e) {
                $this->error("  Failed #{$delivery->id}: {$e->getMessage()}");
            }
        }

        return 0;
    }
}

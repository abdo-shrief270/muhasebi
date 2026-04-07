<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\EInvoice\Models\EtaAmendment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckAmendmentDeadlinesCommand extends Command
{
    protected $signature = 'eta:check-deadlines';

    protected $description = 'Find ETA amendments approaching their deadline (within 1 day) and log warnings';

    public function handle(): int
    {
        $approaching = EtaAmendment::withoutGlobalScopes()
            ->approachingDeadline(withinHours: 24)
            ->with(['etaDocument'])
            ->get();

        $overdue = EtaAmendment::withoutGlobalScopes()
            ->overdue()
            ->with(['etaDocument'])
            ->get();

        if ($approaching->isEmpty() && $overdue->isEmpty()) {
            $this->info('No amendments approaching deadline or overdue.');

            return self::SUCCESS;
        }

        if ($overdue->isNotEmpty()) {
            $this->warn("OVERDUE: {$overdue->count()} amendment(s) past deadline.");

            foreach ($overdue as $amendment) {
                $docId = $amendment->etaDocument?->internal_id ?? $amendment->eta_document_id;
                $hoursOverdue = (int) now()->diffInHours($amendment->deadline_at);

                $message = "Amendment #{$amendment->id} ({$amendment->type->value}) for document [{$docId}] is {$hoursOverdue}h overdue.";

                $this->error("  {$message}");
                Log::warning('ETA amendment overdue.', [
                    'amendment_id' => $amendment->id,
                    'type' => $amendment->type->value,
                    'document_id' => $amendment->eta_document_id,
                    'deadline_at' => $amendment->deadline_at->toIso8601String(),
                    'hours_overdue' => $hoursOverdue,
                    'tenant_id' => $amendment->tenant_id,
                ]);
            }
        }

        if ($approaching->isNotEmpty()) {
            $this->warn("APPROACHING: {$approaching->count()} amendment(s) due within 24 hours.");

            foreach ($approaching as $amendment) {
                $docId = $amendment->etaDocument?->internal_id ?? $amendment->eta_document_id;
                $hoursLeft = (int) now()->diffInHours($amendment->deadline_at);

                $message = "Amendment #{$amendment->id} ({$amendment->type->value}) for document [{$docId}] due in {$hoursLeft}h.";

                $this->line("  {$message}");
                Log::warning('ETA amendment deadline approaching.', [
                    'amendment_id' => $amendment->id,
                    'type' => $amendment->type->value,
                    'document_id' => $amendment->eta_document_id,
                    'deadline_at' => $amendment->deadline_at->toIso8601String(),
                    'hours_remaining' => $hoursLeft,
                    'tenant_id' => $amendment->tenant_id,
                ]);
            }
        }

        $this->info("Done. Overdue: {$overdue->count()}, Approaching: {$approaching->count()}");

        return self::SUCCESS;
    }
}

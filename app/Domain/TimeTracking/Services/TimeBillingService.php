<?php

declare(strict_types=1);

namespace App\Domain\TimeTracking\Services;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Services\InvoiceService;
use App\Domain\TimeTracking\Models\TimesheetEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TimeBillingService
{
    public function __construct(
        private readonly InvoiceService $invoiceService,
    ) {}

    /**
     * Preview unbilled approved entries for a client in a date range.
     *
     * @return array<string, mixed>
     */
    public function preview(int $clientId, string $dateFrom, string $dateTo): array
    {
        $entries = TimesheetEntry::query()
            ->with('user')
            ->forClient($clientId)
            ->approved()
            ->unbilled()
            ->dateRange($dateFrom, $dateTo)
            ->orderBy('date')
            ->get();

        $totalHours = '0.00';
        $totalAmount = '0.00';
        foreach ($entries as $entry) {
            $totalHours = bcadd($totalHours, (string) $entry->hours, 2);
            $lineAmount = bcmul((string) $entry->hours, (string) ($entry->hourly_rate ?? '0'), 2);
            $totalAmount = bcadd($totalAmount, $lineAmount, 2);
        }

        return [
            'entries' => $entries,
            'total_hours' => $totalHours,
            'total_amount' => $totalAmount,
            'entry_count' => $entries->count(),
        ];
    }

    /**
     * Generate an invoice from approved unbilled time entries.
     *
     * @param  array<string, mixed>  $options  group_by ('entry'|'task'), hourly_rate_override, vat_rate, notes
     *
     * @throws ValidationException
     */
    public function generateInvoice(int $clientId, string $dateFrom, string $dateTo, array $options = []): Invoice
    {
        return DB::transaction(function () use ($clientId, $dateFrom, $dateTo, $options): Invoice {
            $entries = TimesheetEntry::query()
                ->forClient($clientId)
                ->approved()
                ->unbilled()
                ->dateRange($dateFrom, $dateTo)
                ->orderBy('date')
                ->get();

            if ($entries->isEmpty()) {
                throw ValidationException::withMessages([
                    'entries' => [
                        'No unbilled approved entries found for this client in the given date range.',
                        'لا توجد قيود معتمدة غير مفوترة لهذا العميل في الفترة المحددة.',
                    ],
                ]);
            }

            $defaultRate = $options['hourly_rate_override'] ?? null;
            $vatRate = $options['vat_rate'] ?? 14;
            $groupBy = $options['group_by'] ?? 'entry';

            $lines = [];

            if ($groupBy === 'task') {
                // Group entries by task_description
                $grouped = $entries->groupBy('task_description');

                foreach ($grouped as $description => $groupEntries) {
                    $totalHours = '0.00';
                    foreach ($groupEntries as $ge) {
                        $totalHours = bcadd($totalHours, (string) $ge->hours, 2);
                    }
                    $rate = (string) ($defaultRate ?? $groupEntries->first()->hourly_rate ?? '0');

                    $lines[] = [
                        'description' => $description." ({$dateFrom} - {$dateTo})",
                        'quantity' => $totalHours,
                        'unit_price' => $rate,
                        'vat_rate' => $vatRate,
                    ];
                }
            } else {
                // One line per entry
                foreach ($entries as $entry) {
                    $rate = (string) ($defaultRate ?? $entry->hourly_rate ?? '0');

                    $lines[] = [
                        'description' => $entry->task_description.' - '.$entry->date->toDateString(),
                        'quantity' => (string) $entry->hours,
                        'unit_price' => $rate,
                        'vat_rate' => $vatRate,
                    ];
                }
            }

            $invoice = $this->invoiceService->create([
                'client_id' => $clientId,
                'date' => today()->toDateString(),
                'notes' => $options['notes'] ?? "فاتورة ساعات عمل من {$dateFrom} إلى {$dateTo}",
                'lines' => $lines,
            ]);

            // Mark entries as billed
            TimesheetEntry::query()
                ->whereIn('id', $entries->pluck('id'))
                ->update(['invoice_id' => $invoice->id]);

            return $invoice;
        });
    }
}

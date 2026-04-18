<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\ScheduledReport;
use App\Mail\ScheduledReportMail;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ReportSchedulerService
{
    public function __construct(
        private readonly ReportService $reportService,
        private readonly TaxReportService $taxReportService,
        private readonly ReportCurrencyConverter $currencyConverter,
    ) {}

    /**
     * List scheduled reports with optional filters.
     *
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = ScheduledReport::query()->latest('created_at');

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active'] === 'true' || $filters['is_active'] === true);
        }

        if (isset($filters['report_type'])) {
            $query->where('report_type', $filters['report_type']);
        }

        if (isset($filters['schedule_type'])) {
            $query->where('schedule_type', $filters['schedule_type']);
        }

        return $query->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Create a new scheduled report.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): ScheduledReport
    {
        $report = ScheduledReport::create($data);

        $report->update([
            'next_send_at' => $this->calculateNextSend($report),
        ]);

        return $report->fresh();
    }

    /**
     * Update a scheduled report.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(ScheduledReport $report, array $data): ScheduledReport
    {
        $report->update($data);

        // Recalculate next_send_at if schedule changed
        if (isset($data['schedule_type']) || isset($data['schedule_day']) || isset($data['schedule_time'])) {
            $report->update([
                'next_send_at' => $this->calculateNextSend($report->fresh()),
            ]);
        }

        return $report->fresh();
    }

    /**
     * Soft-delete a scheduled report.
     */
    public function delete(ScheduledReport $report): void
    {
        $report->delete();
    }

    /**
     * Toggle active/inactive state.
     */
    public function toggle(ScheduledReport $report): ScheduledReport
    {
        $isActive = ! $report->is_active;

        $data = ['is_active' => $isActive];

        // Recalculate next_send_at when re-activating
        if ($isActive) {
            $data['next_send_at'] = $this->calculateNextSend($report);
        }

        $report->update($data);

        return $report->fresh();
    }

    /**
     * Process all due scheduled reports across all tenants.
     */
    public function processDue(): int
    {
        $dueReports = ScheduledReport::due()->get();
        $processed = 0;

        foreach ($dueReports as $report) {
            try {
                // Set tenant context
                app()->instance('tenant.id', $report->tenant_id);

                $this->sendNow($report);
                $processed++;
            } catch (\Throwable $e) {
                Log::error('Failed to process scheduled report', [
                    'scheduled_report_id' => $report->id,
                    'tenant_id' => $report->tenant_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $processed;
    }

    /**
     * Manually trigger a report send.
     */
    public function sendNow(ScheduledReport $report): void
    {
        $pdfContent = $this->generateReport($report);
        $filename = $this->generateFilename($report);
        $subject = $this->resolveSubject($report);

        foreach ($report->recipients as $email) {
            Mail::to($email)->send(new ScheduledReportMail(
                mailSubject: $subject,
                reportName: $this->reportTypeLabel($report->report_type),
                attachmentContent: $pdfContent,
                attachmentFilename: $filename,
            ));
        }

        $report->update([
            'last_sent_at' => now(),
            'next_send_at' => $this->calculateNextSend($report),
        ]);
    }

    /**
     * Calculate the next send datetime based on schedule configuration.
     */
    public function calculateNextSend(ScheduledReport $report): Carbon
    {
        $now = Carbon::now();
        $time = $report->schedule_time ?? '08:00';
        [$hour, $minute] = explode(':', $time);

        return match ($report->schedule_type) {
            'daily' => $this->nextDaily($now, (int) $hour, (int) $minute),
            'weekly' => $this->nextWeekly($now, $report->schedule_day ?? 1, (int) $hour, (int) $minute),
            'monthly' => $this->nextMonthly($now, $report->schedule_day ?? 1, (int) $hour, (int) $minute),
            'quarterly' => $this->nextQuarterly($now, $report->schedule_day ?? 1, (int) $hour, (int) $minute),
            default => $now->addDay()->setTime((int) $hour, (int) $minute, 0),
        };
    }

    // ── Private Helpers ──────────────────────────────────────

    private function nextDaily(Carbon $now, int $hour, int $minute): Carbon
    {
        $next = $now->copy()->setTime($hour, $minute, 0);

        if ($next->lte($now)) {
            $next->addDay();
        }

        return $next;
    }

    private function nextWeekly(Carbon $now, int $dayOfWeek, int $hour, int $minute): Carbon
    {
        // dayOfWeek: 1=Monday ... 7=Sunday (ISO)
        $next = $now->copy()->setTime($hour, $minute, 0);
        $next->next(Carbon::getDays()[$dayOfWeek % 7]);

        return $next;
    }

    private function nextMonthly(Carbon $now, int $dayOfMonth, int $hour, int $minute): Carbon
    {
        $next = $now->copy()->setTime($hour, $minute, 0)->day(min($dayOfMonth, $now->daysInMonth));

        if ($next->lte($now)) {
            $next->addMonthNoOverflow();
            $next->day(min($dayOfMonth, $next->daysInMonth));
        }

        return $next;
    }

    private function nextQuarterly(Carbon $now, int $dayOfMonth, int $hour, int $minute): Carbon
    {
        // Next quarter start: months 1,4,7,10
        $currentQuarterMonth = (int) (ceil($now->month / 3) * 3) + 1;

        if ($currentQuarterMonth > 12) {
            $next = $now->copy()->addYear()->month(1);
        } else {
            $next = $now->copy()->month($currentQuarterMonth);
        }

        $next->day(min($dayOfMonth, $next->daysInMonth))->setTime($hour, $minute, 0);

        // If we computed a date in the past (edge case), push to next quarter
        if ($next->lte($now)) {
            $next->addMonthsNoOverflow(3);
            $next->day(min($dayOfMonth, $next->daysInMonth));
        }

        return $next;
    }

    /**
     * Generate report content (PDF binary string).
     */
    private function generateReport(ScheduledReport $report): string
    {
        $config = $report->report_config ?? [];
        $from = $config['from'] ?? null;
        $to = $config['to'] ?? null;
        $asOf = $config['as_of'] ?? $to ?? now()->format('Y-m-d');
        $currency = $config['currency'] ?? null;

        $data = match ($report->report_type) {
            'trial_balance' => $this->reportService->trialBalance($from, $to),
            'income_statement' => $this->reportService->incomeStatement($from, $to),
            'balance_sheet' => $this->reportService->balanceSheet($asOf),
            'cash_flow' => $this->reportService->cashFlowStatement($from, $to),
            'vat_return' => $this->taxReportService->vatReturn($from ?? now()->startOfMonth()->format('Y-m-d'), $to ?? now()->format('Y-m-d')),
            default => $this->reportService->trialBalance($from, $to),
        };

        if ($currency && in_array($report->report_type, ['trial_balance', 'income_statement', 'balance_sheet', 'cash_flow'])) {
            $data = match ($report->report_type) {
                'trial_balance' => $this->currencyConverter->convertTrialBalance($data, strtoupper($currency), $to),
                'income_statement' => $this->currencyConverter->convertIncomeStatement($data, strtoupper($currency)),
                'balance_sheet' => $this->currencyConverter->convertBalanceSheet($data, strtoupper($currency)),
                'cash_flow' => $this->currencyConverter->convertCashFlow($data, strtoupper($currency)),
                default => $data,
            };
        }

        $viewMap = [
            'trial_balance' => 'reports.trial-balance',
            'income_statement' => 'reports.income-statement',
            'balance_sheet' => 'reports.balance-sheet',
            'cash_flow' => 'reports.cash-flow',
            'vat_return' => 'reports.vat-return',
        ];

        $view = $viewMap[$report->report_type] ?? 'reports.trial-balance';
        $tenant = app('tenant');

        $pdf = Pdf::loadView($view, [
            'data' => $data,
            'tenant' => $tenant,
            'generatedAt' => now()->format('Y-m-d H:i'),
        ]);

        $pdf->setPaper('a4', $report->report_type === 'trial_balance' ? 'landscape' : 'portrait');

        return $pdf->output();
    }

    private function generateFilename(ScheduledReport $report): string
    {
        $type = str_replace('_', '-', $report->report_type);
        $date = now()->format('Y-m-d');

        return "{$type}-{$date}.pdf";
    }

    private function resolveSubject(ScheduledReport $report): string
    {
        if ($report->subject_template) {
            return str_replace(
                ['{report_type}', '{date}'],
                [$this->reportTypeLabel($report->report_type), now()->format('Y-m-d')],
                $report->subject_template,
            );
        }

        return "تقرير {$this->reportTypeLabel($report->report_type)} - ".now()->format('Y-m-d');
    }

    private function reportTypeLabel(string $type): string
    {
        return match ($type) {
            'trial_balance' => 'ميزان المراجعة',
            'income_statement' => 'قائمة الدخل',
            'balance_sheet' => 'الميزانية العمومية',
            'cash_flow' => 'التدفقات النقدية',
            'aging_report' => 'تقرير الأعمار',
            'vat_return' => 'إقرار القيمة المضافة',
            'custom' => 'تقرير مخصص',
            default => $type,
        };
    }
}

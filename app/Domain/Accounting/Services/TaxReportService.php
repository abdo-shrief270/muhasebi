<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Enums\JournalEntryStatus;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Enums\InvoiceType;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceLine;
use Illuminate\Support\Facades\DB;

class TaxReportService
{
    /**
     * Generate Egyptian VAT Return (Form 10 - إقرار ضريبة القيمة المضافة).
     *
     * Calculates output VAT from sales invoices and input VAT from
     * GL entries posted to VAT-related liability accounts (2131-2134).
     *
     * @return array<string, mixed>
     */
    public function vatReturn(string $fromDate, string $toDate): array
    {
        $tenantId = (int) app('tenant.id');

        // ── Output VAT (ضريبة المخرجات) ──
        // VAT collected on sales invoices (excluding drafts and cancelled)
        $outputVat = $this->calculateOutputVat($tenantId, $fromDate, $toDate);

        // ── Input VAT (ضريبة المدخلات) ──
        // VAT paid on purchases — derived from debit entries to VAT accounts
        $inputVat = $this->calculateInputVat($tenantId, $fromDate, $toDate);

        // ── VAT by Rate ──
        $vatByRate = $this->vatBreakdownByRate($tenantId, $fromDate, $toDate);

        // ── Net VAT ──
        $netVat = bcsub($outputVat['total'], $inputVat['total'], 2);
        $isPayable = bccomp($netVat, '0', 2) > 0;

        return [
            'period' => [
                'from' => $fromDate,
                'to' => $toDate,
            ],
            'output_vat' => $outputVat,
            'input_vat' => $inputVat,
            'vat_by_rate' => $vatByRate,
            'net_vat' => $netVat,
            'is_payable' => $isPayable,
            'summary' => [
                'total_sales' => $outputVat['taxable_amount'],
                'total_sales_vat' => $outputVat['total'],
                'total_purchases_vat' => $inputVat['total'],
                'net_vat_due' => $isPayable ? $netVat : '0.00',
                'net_vat_refundable' => ! $isPayable ? bcmul($netVat, '-1', 2) : '0.00',
                'credit_notes_vat' => $outputVat['credit_notes_vat'],
            ],
            'generated_at' => now()->format('Y-m-d H:i'),
            'currency' => 'EGP',
        ];
    }

    /**
     * Generate Withholding Tax (WHT) Report (ضريبة الخصم والإضافة).
     *
     * Calculates WHT from journal entries posted to WHT-related accounts.
     * Egyptian WHT rates: 1% goods, 3% services, 5% professional services.
     *
     * @return array<string, mixed>
     */
    public function whtReport(string $fromDate, string $toDate): array
    {
        $tenantId = (int) app('tenant.id');

        // WHT entries are credit entries to WHT liability accounts
        // Typically accounts in the 2132-2134 range
        $whtEntries = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->join('accounts', 'journal_entry_lines.account_id', '=', 'accounts.id')
            ->where('journal_entries.tenant_id', $tenantId)
            ->where('journal_entries.status', JournalEntryStatus::Posted->value)
            ->whereNull('journal_entries.deleted_at')
            ->whereBetween('journal_entries.date', [$fromDate, $toDate])
            ->where('accounts.code', 'like', '213%')
            ->where('accounts.code', '!=', config('accounting.default_accounts.vat_output')) // Exclude main VAT account
            ->select(
                'accounts.code as account_code',
                'accounts.name_ar as account_name_ar',
                'accounts.name_en as account_name_en',
                'journal_entries.date',
                'journal_entries.entry_number',
                'journal_entry_lines.description',
                'journal_entry_lines.debit',
                'journal_entry_lines.credit',
            )
            ->orderBy('journal_entries.date')
            ->get();

        // Group by account
        $byAccount = $whtEntries->groupBy('account_code')->map(function ($entries, $code) {
            $totalDebit = $entries->sum('debit');
            $totalCredit = $entries->sum('credit');
            $net = bcsub((string) $totalCredit, (string) $totalDebit, 2);

            return [
                'account_code' => $code,
                'account_name_ar' => $entries->first()->account_name_ar,
                'account_name_en' => $entries->first()->account_name_en,
                'entries_count' => $entries->count(),
                'total_withheld' => number_format((float) $net, 2, '.', ''),
                'entries' => $entries->map(fn ($e) => [
                    'date' => $e->date,
                    'entry_number' => $e->entry_number,
                    'description' => $e->description,
                    'amount' => number_format((float) ($e->credit - $e->debit), 2, '.', ''),
                ])->values()->toArray(),
            ];
        })->values()->toArray();

        $totalWithheld = collect($byAccount)->sum(fn ($a) => (float) $a['total_withheld']);

        return [
            'period' => [
                'from' => $fromDate,
                'to' => $toDate,
            ],
            'by_account' => $byAccount,
            'total_withheld' => number_format($totalWithheld, 2, '.', ''),
            'entries_count' => $whtEntries->count(),
            'generated_at' => now()->format('Y-m-d H:i'),
            'currency' => 'EGP',
        ];
    }

    /**
     * Calculate Output VAT from sales invoices.
     *
     * @return array<string, mixed>
     */
    private function calculateOutputVat(int $tenantId, string $fromDate, string $toDate): array
    {
        $excludedStatuses = [InvoiceStatus::Draft, InvoiceStatus::Cancelled];

        // Sales invoices VAT
        $salesVat = Invoice::query()
            ->where('tenant_id', $tenantId)
            ->whereNotIn('status', $excludedStatuses)
            ->where('type', InvoiceType::Standard)
            ->whereBetween('date', [$fromDate, $toDate])
            ->selectRaw('COALESCE(SUM(vat_amount), 0) as total_vat')
            ->selectRaw('COALESCE(SUM(subtotal), 0) as taxable_amount')
            ->selectRaw('COUNT(*) as invoice_count')
            ->first();

        // Credit notes reduce output VAT
        $creditNotesVat = Invoice::query()
            ->where('tenant_id', $tenantId)
            ->whereNotIn('status', $excludedStatuses)
            ->where('type', InvoiceType::CreditNote)
            ->whereBetween('date', [$fromDate, $toDate])
            ->selectRaw('COALESCE(SUM(vat_amount), 0) as total_vat')
            ->selectRaw('COUNT(*) as count')
            ->first();

        $netOutputVat = bcsub(
            (string) ($salesVat->total_vat ?? '0'),
            (string) ($creditNotesVat->total_vat ?? '0'),
            2,
        );

        return [
            'total' => $netOutputVat,
            'taxable_amount' => number_format((float) ($salesVat->taxable_amount ?? 0), 2, '.', ''),
            'sales_vat' => number_format((float) ($salesVat->total_vat ?? 0), 2, '.', ''),
            'credit_notes_vat' => number_format((float) ($creditNotesVat->total_vat ?? 0), 2, '.', ''),
            'invoice_count' => (int) ($salesVat->invoice_count ?? 0),
            'credit_notes_count' => (int) ($creditNotesVat->count ?? 0),
        ];
    }

    /**
     * Calculate Input VAT from GL entries (debit entries to VAT accounts).
     *
     * Input VAT is tracked as debit entries to the VAT liability account (2131)
     * which represent VAT paid on purchases.
     *
     * @return array<string, mixed>
     */
    private function calculateInputVat(int $tenantId, string $fromDate, string $toDate): array
    {
        $result = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->join('accounts', 'journal_entry_lines.account_id', '=', 'accounts.id')
            ->where('journal_entries.tenant_id', $tenantId)
            ->where('journal_entries.status', JournalEntryStatus::Posted->value)
            ->whereNull('journal_entries.deleted_at')
            ->whereBetween('journal_entries.date', [$fromDate, $toDate])
            ->where('accounts.code', config('accounting.default_accounts.vat_output')) // Main VAT liability account
            ->selectRaw('COALESCE(SUM(journal_entry_lines.debit), 0) as total_debit')
            ->selectRaw('COUNT(DISTINCT journal_entries.id) as entry_count')
            ->first();

        return [
            'total' => number_format((float) ($result->total_debit ?? 0), 2, '.', ''),
            'entry_count' => (int) ($result->entry_count ?? 0),
        ];
    }

    /**
     * Break down VAT by rate from invoice lines.
     *
     * @return array<int, array<string, mixed>>
     */
    private function vatBreakdownByRate(int $tenantId, string $fromDate, string $toDate): array
    {
        $excludedStatuses = [InvoiceStatus::Draft, InvoiceStatus::Cancelled];

        $breakdown = InvoiceLine::query()
            ->join('invoices', 'invoice_lines.invoice_id', '=', 'invoices.id')
            ->where('invoices.tenant_id', $tenantId)
            ->whereNotIn('invoices.status', $excludedStatuses)
            ->whereBetween('invoices.date', [$fromDate, $toDate])
            ->groupBy('invoice_lines.vat_rate')
            ->selectRaw('invoice_lines.vat_rate')
            ->selectRaw('COALESCE(SUM(invoice_lines.line_total), 0) as taxable_amount')
            ->selectRaw('COALESCE(SUM(invoice_lines.vat_amount), 0) as vat_amount')
            ->selectRaw('COUNT(*) as line_count')
            ->orderBy('invoice_lines.vat_rate')
            ->get();

        return $breakdown->map(fn ($row) => [
            'rate' => number_format((float) $row->vat_rate, 2, '.', ''),
            'rate_label' => $row->vat_rate == 0 ? 'معفى / Exempt' : "{$row->vat_rate}%",
            'taxable_amount' => number_format((float) $row->taxable_amount, 2, '.', ''),
            'vat_amount' => number_format((float) $row->vat_amount, 2, '.', ''),
            'line_count' => $row->line_count,
        ])->toArray();
    }
}

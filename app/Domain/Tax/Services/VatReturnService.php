<?php

declare(strict_types=1);

namespace App\Domain\Tax\Services;

use App\Domain\Accounting\Enums\JournalEntryStatus;
use App\Domain\AccountsPayable\Enums\BillStatus;
use App\Domain\AccountsPayable\Models\Bill;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Enums\InvoiceType;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceLine;
use App\Domain\Tax\Enums\TaxReturnStatus;
use App\Domain\Tax\Enums\TaxReturnType;
use App\Domain\Tax\Models\TaxReturn;
use Illuminate\Support\Facades\DB;

class VatReturnService
{
    /**
     * Calculate an enhanced VAT return for the given period.
     *
     * Steps:
     * 1. Output VAT from sales invoices
     * 2. Input VAT from purchase bills
     * 3. Net VAT = output - input
     * 4. VAT exempt and zero-rated sales breakdown
     * 5. Reverse charge amounts
     * 6. Create TaxReturn record
     * 7. Return detailed breakdown
     */
    public function calculate(string $from, string $to): TaxReturn
    {
        $tenantId = (int) app('tenant.id');

        // 1. Output VAT from sales invoices
        $outputVat = $this->calculateOutputVat($tenantId, $from, $to);

        // 2. Input VAT from purchase bills
        $inputVat = $this->calculateInputVatFromBills($tenantId, $from, $to);

        // 3. Net VAT
        $netVat = bcsub($outputVat['total'], $inputVat['total'], 2);
        $isPayable = bccomp($netVat, '0', 2) > 0;

        // 4. VAT exempt and zero-rated breakdown
        $vatBreakdown = $this->vatBreakdownByRate($tenantId, $from, $to);
        $exemptSales = $this->getExemptSales($tenantId, $from, $to);
        $zeroRatedSales = $this->getZeroRatedSales($tenantId, $from, $to);

        // 5. Reverse charge amounts (from GL entries on reverse charge accounts)
        $reverseCharge = $this->calculateReverseCharge($tenantId, $from, $to);

        // Determine return type based on period length
        $periodMonths = (int) ceil(
            (strtotime($to) - strtotime($from)) / (30 * 86400),
        );
        $returnType = $periodMonths > 1
            ? TaxReturnType::VatQuarterly
            : TaxReturnType::VatMonthly;

        // 6. Build data breakdown
        $data = [
            'period' => ['from' => $from, 'to' => $to],
            'output_vat' => $outputVat,
            'input_vat' => $inputVat,
            'net_vat' => $netVat,
            'is_payable' => $isPayable,
            'vat_by_rate' => $vatBreakdown,
            'exempt_sales' => $exemptSales,
            'zero_rated_sales' => $zeroRatedSales,
            'reverse_charge' => $reverseCharge,
            'summary' => [
                'total_sales' => $outputVat['taxable_amount'],
                'total_sales_vat' => $outputVat['total'],
                'total_purchases' => $inputVat['taxable_amount'],
                'total_purchases_vat' => $inputVat['total'],
                'net_vat_due' => $isPayable ? $netVat : '0.00',
                'net_vat_refundable' => ! $isPayable ? bcmul($netVat, '-1', 2) : '0.00',
                'credit_notes_vat' => $outputVat['credit_notes_vat'],
                'reverse_charge_vat' => $reverseCharge['total'],
            ],
            'generated_at' => now()->format('Y-m-d H:i'),
            'currency' => 'EGP',
        ];

        $taxDue = $isPayable ? $netVat : '0.00';

        // Create or update the TaxReturn
        $taxReturn = TaxReturn::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'type' => $returnType,
                'period_from' => $from,
                'period_to' => $to,
            ],
            [
                'status' => TaxReturnStatus::Calculated,
                'tax_due' => $taxDue,
                'balance' => $taxDue,
                'data' => $data,
            ],
        );

        return $taxReturn->refresh();
    }

    /**
     * Calculate Output VAT from sales invoices.
     *
     * @return array<string, mixed>
     */
    private function calculateOutputVat(int $tenantId, string $from, string $to): array
    {
        $excludedStatuses = [InvoiceStatus::Draft, InvoiceStatus::Cancelled];

        // Sales invoices VAT
        $salesVat = Invoice::query()
            ->where('tenant_id', $tenantId)
            ->whereNotIn('status', $excludedStatuses)
            ->where('type', InvoiceType::Standard)
            ->whereBetween('date', [$from, $to])
            ->selectRaw('COALESCE(SUM(vat_amount), 0) as total_vat')
            ->selectRaw('COALESCE(SUM(subtotal), 0) as taxable_amount')
            ->selectRaw('COUNT(*) as invoice_count')
            ->first();

        // Credit notes reduce output VAT
        $creditNotesVat = Invoice::query()
            ->where('tenant_id', $tenantId)
            ->whereNotIn('status', $excludedStatuses)
            ->where('type', InvoiceType::CreditNote)
            ->whereBetween('date', [$from, $to])
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
     * Calculate Input VAT from purchase bills (approved/paid bills in period).
     *
     * @return array<string, mixed>
     */
    private function calculateInputVatFromBills(int $tenantId, string $from, string $to): array
    {
        $allowedStatuses = [
            BillStatus::Approved,
            BillStatus::Paid,
            BillStatus::PartiallyPaid,
        ];

        $result = Bill::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', $allowedStatuses)
            ->whereBetween('date', [$from, $to])
            ->selectRaw('COALESCE(SUM(vat_amount), 0) as total_vat')
            ->selectRaw('COALESCE(SUM(subtotal), 0) as taxable_amount')
            ->selectRaw('COUNT(*) as bill_count')
            ->first();

        return [
            'total' => number_format((float) ($result->total_vat ?? 0), 2, '.', ''),
            'taxable_amount' => number_format((float) ($result->taxable_amount ?? 0), 2, '.', ''),
            'bill_count' => (int) ($result->bill_count ?? 0),
        ];
    }

    /**
     * Break down VAT by rate from invoice lines.
     *
     * @return array<int, array<string, mixed>>
     */
    private function vatBreakdownByRate(int $tenantId, string $from, string $to): array
    {
        $excludedStatuses = [InvoiceStatus::Draft, InvoiceStatus::Cancelled];

        $breakdown = InvoiceLine::query()
            ->join('invoices', 'invoice_lines.invoice_id', '=', 'invoices.id')
            ->where('invoices.tenant_id', $tenantId)
            ->whereNotIn('invoices.status', $excludedStatuses)
            ->whereBetween('invoices.date', [$from, $to])
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

    /**
     * Get exempt sales total (zero VAT rate, explicitly exempt items).
     *
     * @return array<string, mixed>
     */
    private function getExemptSales(int $tenantId, string $from, string $to): array
    {
        $excludedStatuses = [InvoiceStatus::Draft, InvoiceStatus::Cancelled];

        $result = InvoiceLine::query()
            ->join('invoices', 'invoice_lines.invoice_id', '=', 'invoices.id')
            ->where('invoices.tenant_id', $tenantId)
            ->whereNotIn('invoices.status', $excludedStatuses)
            ->where('invoices.type', InvoiceType::Standard)
            ->whereBetween('invoices.date', [$from, $to])
            ->where('invoice_lines.vat_rate', 0)
            ->selectRaw('COALESCE(SUM(invoice_lines.line_total), 0) as total_amount')
            ->selectRaw('COUNT(*) as line_count')
            ->first();

        return [
            'total' => number_format((float) ($result->total_amount ?? 0), 2, '.', ''),
            'line_count' => (int) ($result->line_count ?? 0),
        ];
    }

    /**
     * Get zero-rated sales (exports and similar).
     * In Egyptian VAT, zero-rated and exempt are treated differently.
     * Zero-rated: VAT rate = 0 but allows input VAT recovery.
     * This is a simplified implementation — in practice, a flag on the invoice line
     * or product would distinguish exempt vs zero-rated.
     *
     * @return array<string, mixed>
     */
    private function getZeroRatedSales(int $tenantId, string $from, string $to): array
    {
        // For now, zero-rated sales are tracked the same as exempt.
        // A future enhancement would add a `vat_category` column to invoice_lines.
        return [
            'total' => '0.00',
            'line_count' => 0,
        ];
    }

    /**
     * Calculate reverse charge VAT from GL entries.
     * Reverse charge applies when buying services from non-resident suppliers.
     * These are tracked as credit entries to a reverse charge VAT account.
     *
     * @return array<string, mixed>
     */
    private function calculateReverseCharge(int $tenantId, string $from, string $to): array
    {
        // Reverse charge VAT is typically posted to a specific sub-account
        // under the VAT liability group (e.g., 2135 or similar).
        $result = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->join('accounts', 'journal_entry_lines.account_id', '=', 'accounts.id')
            ->where('journal_entries.tenant_id', $tenantId)
            ->where('journal_entries.status', JournalEntryStatus::Posted->value)
            ->whereNull('journal_entries.deleted_at')
            ->whereBetween('journal_entries.date', [$from, $to])
            ->where('accounts.code', 'like', '2135%')
            ->selectRaw('COALESCE(SUM(journal_entry_lines.credit), 0) as total_credit')
            ->selectRaw('COALESCE(SUM(journal_entry_lines.debit), 0) as total_debit')
            ->selectRaw('COUNT(DISTINCT journal_entries.id) as entry_count')
            ->first();

        $total = bcsub(
            (string) ($result->total_credit ?? '0'),
            (string) ($result->total_debit ?? '0'),
            2,
        );

        // Ensure non-negative
        if (bccomp($total, '0', 2) < 0) {
            $total = '0.00';
        }

        return [
            'total' => $total,
            'entry_count' => (int) ($result->entry_count ?? 0),
        ];
    }
}

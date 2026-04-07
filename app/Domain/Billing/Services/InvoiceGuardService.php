<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Client\Models\Client;

/**
 * Business rule guard for invoice creation.
 * Checks: credit limits, duplicate detection, fiscal period locks.
 *
 * Usage (in InvoiceService::create):
 *   $warnings = InvoiceGuardService::check($tenantId, $clientId, $total);
 *   // Returns array of warnings (empty = all clear)
 */
class InvoiceGuardService
{
    /**
     * Run all invoice creation checks.
     * Returns array of warnings/errors. Empty = safe to proceed.
     */
    public static function check(int $tenantId, int $clientId, float $newInvoiceTotal, ?string $date = null): array
    {
        $warnings = [];

        // 1. Credit limit check
        $creditWarning = self::checkCreditLimit($tenantId, $clientId, $newInvoiceTotal);
        if ($creditWarning) $warnings[] = $creditWarning;

        // 2. Duplicate invoice detection
        $dupeWarning = self::checkDuplicate($tenantId, $clientId, $newInvoiceTotal);
        if ($dupeWarning) $warnings[] = $dupeWarning;

        // 3. Fiscal period lock check
        if ($date) {
            $lockWarning = self::checkFiscalPeriod($tenantId, $date);
            if ($lockWarning) $warnings[] = $lockWarning;
        }

        return $warnings;
    }

    /**
     * Check if creating this invoice would exceed the client's credit limit.
     * Returns warning message or null.
     */
    public static function checkCreditLimit(int $tenantId, int $clientId, float $newTotal): ?array
    {
        $client = Client::where('id', $clientId)->where('tenant_id', $tenantId)->first();
        if (! $client || $client->credit_limit === null) {
            return null; // No credit limit set
        }

        // Calculate current outstanding balance (unpaid invoices)
        $outstandingBalance = Invoice::where('tenant_id', $tenantId)
            ->where('client_id', $clientId)
            ->whereIn('status', [
                InvoiceStatus::Sent,
                InvoiceStatus::PartiallyPaid,
                InvoiceStatus::Overdue,
            ])
            ->selectRaw('COALESCE(SUM(total - amount_paid), 0) as outstanding')
            ->value('outstanding');

        $totalExposure = (float) $outstandingBalance + $newTotal;
        $creditLimit = (float) $client->credit_limit;

        if ($totalExposure > $creditLimit) {
            return [
                'type' => 'credit_limit_exceeded',
                'severity' => 'error',
                'message_ar' => sprintf(
                    'تجاوز الحد الائتماني للعميل. الحد: %s، المستحق الحالي: %s، الفاتورة الجديدة: %s، الإجمالي: %s',
                    number_format($creditLimit, 2),
                    number_format((float) $outstandingBalance, 2),
                    number_format($newTotal, 2),
                    number_format($totalExposure, 2),
                ),
                'message_en' => sprintf(
                    'Client credit limit exceeded. Limit: %s, Outstanding: %s, New invoice: %s, Total: %s',
                    number_format($creditLimit, 2),
                    number_format((float) $outstandingBalance, 2),
                    number_format($newTotal, 2),
                    number_format($totalExposure, 2),
                ),
                'data' => [
                    'credit_limit' => $creditLimit,
                    'outstanding' => (float) $outstandingBalance,
                    'new_invoice' => $newTotal,
                    'total_exposure' => $totalExposure,
                    'exceeds_by' => $totalExposure - $creditLimit,
                ],
            ];
        }

        return null;
    }

    /**
     * Check for potential duplicate invoices.
     * Flags if same client + similar amount within 24 hours.
     */
    public static function checkDuplicate(int $tenantId, int $clientId, float $total): ?array
    {
        $tolerance = 0.01; // Allow 0.01 difference (rounding)

        $recent = Invoice::where('tenant_id', $tenantId)
            ->where('client_id', $clientId)
            ->where('created_at', '>=', now()->subHours(24))
            ->whereBetween('total', [$total - $tolerance, $total + $tolerance])
            ->first();

        if ($recent) {
            return [
                'type' => 'possible_duplicate',
                'severity' => 'warning',
                'message_ar' => sprintf(
                    'تحذير: تم إنشاء فاتورة مشابهة لنفس العميل بنفس المبلغ (%s) خلال آخر 24 ساعة (فاتورة رقم %s).',
                    number_format($total, 2),
                    $recent->invoice_number,
                ),
                'message_en' => sprintf(
                    'Warning: A similar invoice for the same client with the same amount (%s) was created in the last 24 hours (Invoice #%s).',
                    number_format($total, 2),
                    $recent->invoice_number,
                ),
                'data' => [
                    'existing_invoice_id' => $recent->id,
                    'existing_invoice_number' => $recent->invoice_number,
                    'existing_invoice_total' => (float) $recent->total,
                    'existing_invoice_created_at' => $recent->created_at->toISOString(),
                ],
            ];
        }

        return null;
    }

    /**
     * Check if the invoice date falls in a locked fiscal period.
     */
    public static function checkFiscalPeriod(int $tenantId, string $date): ?array
    {
        if (! \App\Domain\Accounting\Services\FiscalPeriodLockService::isPeriodOpen($tenantId, $date)) {
            return [
                'type' => 'fiscal_period_locked',
                'severity' => 'error',
                'message_ar' => 'الفترة المحاسبية لهذا التاريخ مغلقة. لا يمكن إنشاء فواتير في فترة مغلقة.',
                'message_en' => 'The fiscal period for this date is locked. Cannot create invoices in a closed period.',
            ];
        }

        return null;
    }
}

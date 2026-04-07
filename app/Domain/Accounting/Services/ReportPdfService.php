<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

class ReportPdfService
{
    public function __construct(
        private readonly ReportService $reportService,
        private readonly TaxReportService $taxReportService,
        private readonly ReportCurrencyConverter $currencyConverter,
    ) {}

    /**
     * Generate Income Statement PDF.
     */
    public function incomeStatementPdf(?string $from = null, ?string $to = null, ?string $currency = null): Response
    {
        $data = $this->reportService->incomeStatement($from, $to);

        if ($currency) {
            $data = $this->currencyConverter->convertIncomeStatement($data, strtoupper($currency));
        }

        $tenant = app('tenant');
        $currencyLabel = $currency ? strtoupper($currency) : 'EGP';

        $pdf = Pdf::loadView('reports.income-statement', [
            'data' => $data,
            'tenant' => $tenant,
            'generatedAt' => now()->format('Y-m-d H:i'),
        ]);

        $pdf->setPaper('a4', 'portrait');

        $fromLabel = $from ?? 'inception';
        $toLabel = $to ?? date('Y-m-d');

        return $pdf->download("income-statement-{$fromLabel}-{$toLabel}-{$currencyLabel}.pdf");
    }

    /**
     * Generate Balance Sheet PDF.
     */
    public function balanceSheetPdf(?string $asOf = null, ?string $currency = null): Response
    {
        $data = $this->reportService->balanceSheet($asOf);

        if ($currency) {
            $data = $this->currencyConverter->convertBalanceSheet($data, strtoupper($currency));
        }

        $tenant = app('tenant');
        $currencyLabel = $currency ? strtoupper($currency) : 'EGP';

        $pdf = Pdf::loadView('reports.balance-sheet', [
            'data' => $data,
            'tenant' => $tenant,
            'generatedAt' => now()->format('Y-m-d H:i'),
        ]);

        $pdf->setPaper('a4', 'portrait');

        $asOfLabel = $asOf ?? date('Y-m-d');

        return $pdf->download("balance-sheet-{$asOfLabel}-{$currencyLabel}.pdf");
    }

    /**
     * Generate Cash Flow Statement PDF.
     */
    public function cashFlowPdf(?string $from = null, ?string $to = null, ?string $currency = null): Response
    {
        $data = $this->reportService->cashFlowStatement($from, $to);

        if ($currency) {
            $data = $this->currencyConverter->convertCashFlow($data, strtoupper($currency));
        }

        $tenant = app('tenant');
        $currencyLabel = $currency ? strtoupper($currency) : 'EGP';

        $pdf = Pdf::loadView('reports.cash-flow', [
            'data' => $data,
            'tenant' => $tenant,
            'generatedAt' => now()->format('Y-m-d H:i'),
        ]);

        $pdf->setPaper('a4', 'portrait');

        $fromLabel = $from ?? 'inception';
        $toLabel = $to ?? date('Y-m-d');

        return $pdf->download("cash-flow-{$fromLabel}-{$toLabel}-{$currencyLabel}.pdf");
    }

    /**
     * Generate Trial Balance PDF.
     */
    public function trialBalancePdf(?string $from = null, ?string $to = null, ?string $currency = null): Response
    {
        $data = $this->reportService->trialBalance($from, $to);

        if ($currency) {
            $data = $this->currencyConverter->convertTrialBalance($data, strtoupper($currency), $to);
        }

        $tenant = app('tenant');
        $currencyLabel = $currency ? strtoupper($currency) : 'EGP';

        $pdf = Pdf::loadView('reports.trial-balance', [
            'data' => $data,
            'tenant' => $tenant,
            'generatedAt' => now()->format('Y-m-d H:i'),
        ]);

        $pdf->setPaper('a4', 'landscape');

        $fromLabel = $from ?? 'inception';
        $toLabel = $to ?? date('Y-m-d');

        return $pdf->download("trial-balance-{$fromLabel}-{$toLabel}-{$currencyLabel}.pdf");
    }

    /**
     * Generate VAT Return PDF.
     */
    public function vatReturnPdf(string $from, string $to): Response
    {
        $data = $this->taxReportService->vatReturn($from, $to);
        $tenant = app('tenant');

        $pdf = Pdf::loadView('reports.vat-return', [
            'data' => $data,
            'tenant' => $tenant,
            'generatedAt' => now()->format('Y-m-d H:i'),
        ]);

        $pdf->setPaper('a4', 'portrait');

        return $pdf->download("vat-return-{$from}-{$to}.pdf");
    }

    /**
     * Generate WHT Report PDF.
     */
    public function whtReportPdf(string $from, string $to): Response
    {
        $data = $this->taxReportService->whtReport($from, $to);
        $tenant = app('tenant');

        $pdf = Pdf::loadView('reports.wht-report', [
            'data' => $data,
            'tenant' => $tenant,
            'generatedAt' => now()->format('Y-m-d H:i'),
        ]);

        $pdf->setPaper('a4', 'portrait');

        return $pdf->download("wht-report-{$from}-{$to}.pdf");
    }
}

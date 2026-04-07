<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Services\ReportCurrencyConverter;
use App\Domain\Accounting\Services\ReportPdfService;
use App\Domain\Accounting\Services\ReportService;
use App\Domain\Accounting\Services\TaxReportService;
use App\Domain\Billing\Services\PaymentService;
use App\Domain\Client\Models\Client;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ReportController extends Controller
{
    public function __construct(
        private readonly ReportService $reportService,
        private readonly PaymentService $paymentService,
        private readonly ReportPdfService $reportPdfService,
        private readonly TaxReportService $taxReportService,
        private readonly ReportCurrencyConverter $currencyConverter,
    ) {}

    public function trialBalance(Request $request): JsonResponse
    {
        $data = $this->reportService->trialBalance(
            fromDate: $request->query('from'),
            toDate: $request->query('to'),
        );

        if ($currency = $request->query('currency')) {
            $data = $this->currencyConverter->convertTrialBalance($data, strtoupper($currency), $request->query('to'));
        }

        return response()->json($data);
    }

    public function accountLedger(Request $request, Account $account): JsonResponse
    {
        $data = $this->reportService->accountLedger(
            account: $account,
            fromDate: $request->query('from'),
            toDate: $request->query('to'),
        );

        return response()->json($data);
    }

    public function clientStatement(Request $request, Client $client): JsonResponse
    {
        $data = $this->paymentService->clientStatement(
            clientId: $client->id,
            fromDate: $request->query('from'),
            toDate: $request->query('to'),
        );

        return response()->json($data);
    }

    public function agingReport(Request $request): JsonResponse
    {
        $data = $this->paymentService->agingReport(
            clientId: $request->query('client_id') ? (int) $request->query('client_id') : null,
        );

        return response()->json($data);
    }

    // ── Financial Statements ─────────────────────────────────

    public function incomeStatement(Request $request): JsonResponse
    {
        $data = $this->reportService->incomeStatement(
            fromDate: $request->query('from'),
            toDate: $request->query('to'),
        );

        if ($currency = $request->query('currency')) {
            $data = $this->currencyConverter->convertIncomeStatement($data, strtoupper($currency));
        }

        // Add convenience top-level keys for consumers
        $data['total_revenue'] = $data['revenue']['total'] ?? '0.00';
        $data['total_expenses'] = $data['expenses']['total'] ?? '0.00';

        return response()->json($data);
    }

    public function balanceSheet(Request $request): JsonResponse
    {
        $data = $this->reportService->balanceSheet(
            asOfDate: $request->query('as_of'),
        );

        if ($currency = $request->query('currency')) {
            $data = $this->currencyConverter->convertBalanceSheet($data, strtoupper($currency));
        }

        // Add convenience top-level keys for consumers
        $data['total_assets'] = $data['assets']['total'] ?? '0.00';
        $data['total_liabilities'] = $data['liabilities']['total'] ?? '0.00';
        $data['total_equity'] = $data['equity']['total'] ?? '0.00';
        $data['net_income'] = $data['equity']['net_income'] ?? '0.00';

        return response()->json($data);
    }

    public function cashFlow(Request $request): JsonResponse
    {
        $data = $this->reportService->cashFlowStatement(
            fromDate: $request->query('from'),
            toDate: $request->query('to'),
        );

        if ($currency = $request->query('currency')) {
            $data = $this->currencyConverter->convertCashFlow($data, strtoupper($currency));
        }

        return response()->json($data);
    }

    // ── Comparative Reports ──────────────────────────────────

    public function comparativeIncomeStatement(Request $request): JsonResponse
    {
        $data = $this->reportService->comparativeIncomeStatement(
            currentFrom: $request->query('current_from'),
            currentTo: $request->query('current_to'),
            priorFrom: $request->query('prior_from'),
            priorTo: $request->query('prior_to'),
        );

        // Add convenience top-level keys to each period
        $data['current']['total_revenue'] = $data['current']['revenue']['total'] ?? '0.00';
        $data['current']['total_expenses'] = $data['current']['expenses']['total'] ?? '0.00';
        $data['prior']['total_revenue'] = $data['prior']['revenue']['total'] ?? '0.00';
        $data['prior']['total_expenses'] = $data['prior']['expenses']['total'] ?? '0.00';
        $data['variance'] = $data['net_income_variance'] ?? [];

        return response()->json($data);
    }

    public function comparativeBalanceSheet(Request $request): JsonResponse
    {
        $data = $this->reportService->comparativeBalanceSheet(
            currentAsOf: $request->query('current_as_of'),
            priorAsOf: $request->query('prior_as_of'),
        );

        // Add convenience top-level keys to each period
        $data['current']['total_assets'] = $data['current']['assets']['total'] ?? '0.00';
        $data['current']['total_liabilities'] = $data['current']['liabilities']['total'] ?? '0.00';
        $data['current']['total_equity'] = $data['current']['equity']['total'] ?? '0.00';
        $data['prior']['total_assets'] = $data['prior']['assets']['total'] ?? '0.00';
        $data['prior']['total_liabilities'] = $data['prior']['liabilities']['total'] ?? '0.00';
        $data['prior']['total_equity'] = $data['prior']['equity']['total'] ?? '0.00';
        $data['variance'] = $data['assets_variance'] ?? [];

        return response()->json($data);
    }

    // ── PDF Exports ──────────────────────────────────────────

    public function incomeStatementPdf(Request $request): Response
    {
        return $this->reportPdfService->incomeStatementPdf(
            $request->query('from'),
            $request->query('to'),
            $request->query('currency'),
        );
    }

    public function balanceSheetPdf(Request $request): Response
    {
        return $this->reportPdfService->balanceSheetPdf(
            $request->query('as_of'),
            $request->query('currency'),
        );
    }

    public function cashFlowPdf(Request $request): Response
    {
        return $this->reportPdfService->cashFlowPdf(
            $request->query('from'),
            $request->query('to'),
            $request->query('currency'),
        );
    }

    public function trialBalancePdf(Request $request): Response
    {
        return $this->reportPdfService->trialBalancePdf(
            $request->query('from'),
            $request->query('to'),
            $request->query('currency'),
        );
    }

    // ── Tax Reports ─────────────────────────────────────────

    public function vatReturn(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $data = $this->taxReportService->vatReturn(
            fromDate: $request->query('from'),
            toDate: $request->query('to'),
        );

        return response()->json($data);
    }

    public function whtReport(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $data = $this->taxReportService->whtReport(
            fromDate: $request->query('from'),
            toDate: $request->query('to'),
        );

        return response()->json($data);
    }

    public function vatReturnPdf(Request $request): Response
    {
        $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        return $this->reportPdfService->vatReturnPdf(
            $request->query('from'),
            $request->query('to'),
        );
    }

    public function whtReportPdf(Request $request): Response
    {
        $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        return $this->reportPdfService->whtReportPdf(
            $request->query('from'),
            $request->query('to'),
        );
    }
}

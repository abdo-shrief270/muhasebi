<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Accounting\Models\BankReconciliation;
use App\Domain\Accounting\Models\BankStatementLine;
use App\Domain\Accounting\Services\BankAutoReconciliationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BankAutoReconciliationController extends Controller
{
    public function __construct(
        private readonly BankAutoReconciliationService $autoReconService,
    ) {}

    /**
     * Run smart matching on all unmatched lines in a reconciliation.
     */
    public function smartMatch(BankReconciliation $bankReconciliation): JsonResponse
    {
        $result = $this->autoReconService->smartMatch($bankReconciliation);

        return response()->json([
            'message' => "Smart match complete: {$result['matched']} matched, {$result['unmatched']} unmatched.",
            'data' => $result,
        ]);
    }

    /**
     * Match a deposit line to a specific invoice.
     */
    public function matchToInvoice(Request $request, BankStatementLine $bankStatementLine): JsonResponse
    {
        $data = $request->validate([
            'invoice_id' => ['required', 'integer', Rule::exists('invoices', 'id')->where('tenant_id', app('tenant.id'))],
        ]);

        $line = $this->autoReconService->matchToInvoice($bankStatementLine, (int) $data['invoice_id']);

        return response()->json([
            'message' => 'Line matched to invoice and payment recorded.',
            'data' => $line->load(['matchedInvoice', 'postedJournalEntry']),
        ]);
    }

    /**
     * Match a withdrawal line to a specific bill.
     */
    public function matchToBill(Request $request, BankStatementLine $bankStatementLine): JsonResponse
    {
        $data = $request->validate([
            'bill_id' => ['required', 'integer', Rule::exists('bills', 'id')->where('tenant_id', app('tenant.id'))],
        ]);

        $line = $this->autoReconService->matchToBill($bankStatementLine, (int) $data['bill_id']);

        return response()->json([
            'message' => 'Line matched to bill and payment recorded.',
            'data' => $line->load(['matchedBill', 'postedJournalEntry']),
        ]);
    }

    /**
     * Auto-post all high-confidence matched lines to GL.
     */
    public function autoPost(BankReconciliation $bankReconciliation): JsonResponse
    {
        $result = $this->autoReconService->autoPost($bankReconciliation);

        return response()->json([
            'message' => "Auto-posted {$result['posted']} lines, {$result['skipped']} skipped.",
            'data' => $result,
        ]);
    }

    /**
     * Get match suggestions for an unmatched line.
     */
    public function suggestions(BankStatementLine $bankStatementLine): JsonResponse
    {
        $suggestions = $this->autoReconService->unmatchedSuggestions($bankStatementLine);

        return response()->json([
            'data' => $suggestions,
        ]);
    }
}

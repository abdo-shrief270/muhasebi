<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Accounting\Models\BankReconciliation;
use App\Domain\Accounting\Models\BankStatementLine;
use App\Domain\Accounting\Services\BankReconciliationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class BankReconciliationController extends Controller
{
    public function __construct(
        private readonly BankReconciliationService $reconciliationService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $this->reconciliationService->list([
            'account_id' => $request->query('account_id'),
            'status' => $request->query('status'),
            'per_page' => min((int) ($request->query('per_page', 15)), 100),
        ]);

        return response()->json($data);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('tenant_id', app('tenant.id'))],
            'statement_date' => ['required', 'date'],
            'statement_balance' => ['required', 'numeric'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $reconciliation = $this->reconciliationService->create($data);

        return response()->json([
            'data' => $reconciliation->load('account:id,code,name_ar,name_en'),
            'message' => 'Bank reconciliation created.',
        ], Response::HTTP_CREATED);
    }

    public function show(BankReconciliation $bankReconciliation): JsonResponse
    {
        return response()->json([
            'data' => $this->reconciliationService->summary($bankReconciliation),
            'lines' => $bankReconciliation->statementLines()
                ->with('journalEntryLine')
                ->orderBy('date')
                ->get(),
        ]);
    }

    public function importLines(Request $request, BankReconciliation $bankReconciliation): JsonResponse
    {
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.date' => ['required', 'date'],
            'lines.*.description' => ['nullable', 'string', 'max:500'],
            'lines.*.reference' => ['nullable', 'string', 'max:100'],
            'lines.*.amount' => ['required', 'numeric'],
            'lines.*.type' => ['nullable', 'string', 'in:deposit,withdrawal'],
        ]);

        $count = $this->reconciliationService->importLines($bankReconciliation, $data['lines']);

        return response()->json([
            'message' => "Imported {$count} statement lines.",
            'imported_count' => $count,
        ]);
    }

    public function autoMatch(BankReconciliation $bankReconciliation): JsonResponse
    {
        $matched = $this->reconciliationService->autoMatch($bankReconciliation);

        return response()->json([
            'message' => "Auto-matched {$matched} transactions.",
            'matched_count' => $matched,
        ]);
    }

    public function manualMatch(Request $request, BankStatementLine $bankStatementLine): JsonResponse
    {
        $data = $request->validate([
            'journal_entry_line_id' => ['required', 'integer', 'exists:journal_entry_lines,id'],
        ]);

        $tenantOwns = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entry_lines.id', $data['journal_entry_line_id'])
            ->where('journal_entries.tenant_id', app('tenant.id'))
            ->exists();

        if (! $tenantOwns) {
            return response()->json(['message' => 'Journal entry line not found.'], 404);
        }

        $line = $this->reconciliationService->manualMatch($bankStatementLine, $data['journal_entry_line_id']);

        return response()->json(['data' => $line->load('journalEntryLine')]);
    }

    public function unmatch(BankStatementLine $bankStatementLine): JsonResponse
    {
        $line = $this->reconciliationService->unmatch($bankStatementLine);

        return response()->json(['data' => $line]);
    }

    public function exclude(BankStatementLine $bankStatementLine): JsonResponse
    {
        $line = $this->reconciliationService->exclude($bankStatementLine);

        return response()->json(['data' => $line]);
    }

    public function complete(BankReconciliation $bankReconciliation): JsonResponse
    {
        $reconciliation = $this->reconciliationService->complete($bankReconciliation);

        return response()->json([
            'data' => $reconciliation,
            'message' => 'Bank reconciliation completed.',
        ]);
    }

    public function summary(BankReconciliation $bankReconciliation): JsonResponse
    {
        return response()->json([
            'data' => $this->reconciliationService->summary($bankReconciliation),
        ]);
    }

    public function destroy(BankReconciliation $bankReconciliation): JsonResponse
    {
        if ($bankReconciliation->isCompleted()) {
            return response()->json([
                'message' => 'Cannot delete a completed reconciliation.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $bankReconciliation->delete();

        return response()->json(['message' => 'Bank reconciliation deleted.']);
    }
}

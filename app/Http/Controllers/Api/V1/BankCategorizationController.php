<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Accounting\Models\BankCategorizationRule;
use App\Domain\Accounting\Models\BankReconciliation;
use App\Domain\Accounting\Models\BankStatementLine;
use App\Domain\Accounting\Services\BankCategorizationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class BankCategorizationController extends Controller
{
    public function __construct(
        private readonly BankCategorizationService $categorizationService,
    ) {}

    /**
     * Auto-categorize unmatched lines for a reconciliation.
     *
     * POST /bank-reconciliations/{recon}/categorize
     */
    public function categorize(BankReconciliation $bankReconciliation): JsonResponse
    {
        $count = $this->categorizationService->categorize($bankReconciliation);

        return response()->json([
            'message' => "Auto-categorized {$count} statement lines.",
            'categorized_count' => $count,
        ]);
    }

    /**
     * List categorization rules.
     *
     * GET /bank-categorization-rules
     */
    public function rules(Request $request): JsonResponse
    {
        $data = $this->categorizationService->listRules([
            'is_active' => $request->query('is_active'),
            'match_type' => $request->query('match_type'),
            'per_page' => min((int) ($request->query('per_page', 15)), 100),
        ]);

        return response()->json($data);
    }

    /**
     * Create a new categorization rule.
     *
     * POST /bank-categorization-rules
     */
    public function createRule(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pattern' => ['required', 'string', 'max:255'],
            'match_type' => ['required', 'string', Rule::in(['contains', 'starts_with', 'regex', 'exact'])],
            'account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('tenant_id', app('tenant.id'))],
            'vendor_id' => ['nullable', 'integer', Rule::exists('vendors', 'id')->where('tenant_id', app('tenant.id'))],
            'priority' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $rule = $this->categorizationService->createRule($data);

        return response()->json([
            'data' => $rule->load('account:id,code,name_ar,name_en'),
            'message' => 'Categorization rule created.',
        ], Response::HTTP_CREATED);
    }

    /**
     * Delete a categorization rule.
     *
     * DELETE /bank-categorization-rules/{rule}
     */
    public function deleteRule(BankCategorizationRule $bankCategorizationRule): JsonResponse
    {
        $this->categorizationService->deleteRule($bankCategorizationRule->id);

        return response()->json(['message' => 'Categorization rule deleted.']);
    }

    /**
     * Apply an auto-suggestion to a statement line.
     *
     * POST /bank-statement-lines/{line}/apply-suggestion
     */
    public function applySuggestion(BankStatementLine $bankStatementLine): JsonResponse
    {
        $line = $this->categorizationService->applySuggestion($bankStatementLine);

        return response()->json([
            'data' => $line->load('suggestedAccount:id,code,name_ar,name_en'),
            'message' => 'Suggestion applied.',
        ]);
    }

    /**
     * Learn from a manual categorization.
     *
     * POST /bank-statement-lines/{line}/learn
     */
    public function learn(Request $request, BankStatementLine $bankStatementLine): JsonResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('tenant_id', app('tenant.id'))],
            'vendor_id' => ['nullable', 'integer', Rule::exists('vendors', 'id')->where('tenant_id', app('tenant.id'))],
        ]);

        $this->categorizationService->learnFromMatch(
            $bankStatementLine,
            (int) $data['account_id'],
            isset($data['vendor_id']) ? (int) $data['vendor_id'] : null,
        );

        return response()->json(['message' => 'Learned from categorization.']);
    }
}

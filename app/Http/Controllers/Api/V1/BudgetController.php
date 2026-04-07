<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Accounting\Models\Budget;
use App\Domain\Accounting\Services\BudgetService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class BudgetController extends Controller
{
    public function __construct(
        private readonly BudgetService $budgetService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $this->budgetService->list([
            'fiscal_year_id' => $request->query('fiscal_year_id'),
            'status' => $request->query('status'),
            'per_page' => $request->query('per_page', 15),
        ]);

        return response()->json($data);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fiscal_year_id' => ['required', 'integer', Rule::exists('fiscal_years', 'id')->where('tenant_id', app('tenant.id'))],
            'name' => ['required', 'string', 'max:255'],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $budget = $this->budgetService->create($data);

        return response()->json([
            'data' => $budget->load('fiscalYear:id,name'),
            'message' => 'Budget created.',
        ], Response::HTTP_CREATED);
    }

    public function show(Budget $budget): JsonResponse
    {
        return response()->json([
            'data' => $budget->load([
                'fiscalYear:id,name,start_date,end_date',
                'lines.account:id,code,name_ar,name_en,type,normal_balance',
                'approvedByUser:id,name',
            ]),
        ]);
    }

    public function update(Request $request, Budget $budget): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $budget->update($data);

        return response()->json(['data' => $budget->refresh()]);
    }

    public function destroy(Budget $budget): JsonResponse
    {
        if (! $budget->isDraft()) {
            return response()->json([
                'message' => 'Only draft budgets can be deleted.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $budget->delete();

        return response()->json(['message' => 'Budget deleted.']);
    }

    public function setLines(Request $request, Budget $budget): JsonResponse
    {
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('tenant_id', app('tenant.id'))],
            'lines.*.annual_amount' => ['required', 'numeric', 'min:0'],
            'lines.*.distribute' => ['nullable', 'boolean'],
            'lines.*.m1' => ['nullable', 'numeric', 'min:0'],
            'lines.*.m2' => ['nullable', 'numeric', 'min:0'],
            'lines.*.m3' => ['nullable', 'numeric', 'min:0'],
            'lines.*.m4' => ['nullable', 'numeric', 'min:0'],
            'lines.*.m5' => ['nullable', 'numeric', 'min:0'],
            'lines.*.m6' => ['nullable', 'numeric', 'min:0'],
            'lines.*.m7' => ['nullable', 'numeric', 'min:0'],
            'lines.*.m8' => ['nullable', 'numeric', 'min:0'],
            'lines.*.m9' => ['nullable', 'numeric', 'min:0'],
            'lines.*.m10' => ['nullable', 'numeric', 'min:0'],
            'lines.*.m11' => ['nullable', 'numeric', 'min:0'],
            'lines.*.m12' => ['nullable', 'numeric', 'min:0'],
        ]);

        $budget = $this->budgetService->setLines($budget, $data['lines']);

        return response()->json(['data' => $budget]);
    }

    public function approve(Budget $budget): JsonResponse
    {
        $budget = $this->budgetService->approve($budget);

        return response()->json([
            'data' => $budget,
            'message' => 'Budget approved.',
        ]);
    }

    public function variance(Request $request, Budget $budget): JsonResponse
    {
        $throughMonth = $request->query('through_month')
            ? (int) $request->query('through_month')
            : null;

        $data = $this->budgetService->variance($budget, $throughMonth);

        return response()->json(['data' => $data]);
    }
}

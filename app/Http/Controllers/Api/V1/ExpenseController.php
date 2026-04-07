<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Expenses\Models\Expense;
use App\Domain\Expenses\Services\ExpenseService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Expenses\RejectExpenseRequest;
use App\Http\Requests\Expenses\StoreExpenseRequest;
use App\Http\Requests\Expenses\UpdateExpenseRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    public function __construct(
        private readonly ExpenseService $expenseService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $expenses = $this->expenseService->list([
            'search' => $request->query('search'),
            'status' => $request->query('status'),
            'user_id' => $request->query('user_id'),
            'category_id' => $request->query('category_id'),
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'sort_by' => $request->query('sort_by', 'date'),
            'sort_dir' => $request->query('sort_dir', 'desc'),
            'per_page' => min((int) ($request->query('per_page', 15)), 100),
        ]);

        return $this->success($expenses);
    }

    public function store(StoreExpenseRequest $request): JsonResponse
    {
        $expense = $this->expenseService->create($request->validated());

        return $this->created($expense);
    }

    public function show(Expense $expense): JsonResponse
    {
        $expense->load(['category', 'user', 'vendor']);

        return $this->success($expense);
    }

    public function update(UpdateExpenseRequest $request, Expense $expense): JsonResponse
    {
        $expense = $this->expenseService->update($expense, $request->validated());

        return $this->success($expense);
    }

    public function destroy(Expense $expense): JsonResponse
    {
        $this->expenseService->delete($expense);

        return $this->deleted('Expense deleted successfully.');
    }

    public function submit(Expense $expense): JsonResponse
    {
        $expense = $this->expenseService->submit($expense);

        return $this->success($expense);
    }

    public function approve(Expense $expense): JsonResponse
    {
        $expense = $this->expenseService->approve($expense);

        return $this->success($expense);
    }

    public function reject(RejectExpenseRequest $request, Expense $expense): JsonResponse
    {
        $expense = $this->expenseService->reject($expense, $request->validated());

        return $this->success($expense);
    }

    public function reimburse(Expense $expense): JsonResponse
    {
        $expense = $this->expenseService->reimburse($expense);

        return $this->success($expense);
    }

    public function bulkSubmit(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer'],
        ]);

        $result = $this->expenseService->bulkSubmit($request->input('ids'));

        return $this->success($result);
    }

    public function summary(Request $request): JsonResponse
    {
        $summary = $this->expenseService->summary([
            'status' => $request->query('status'),
            'user_id' => $request->query('user_id'),
            'category_id' => $request->query('category_id'),
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
        ]);

        return $this->success($summary);
    }
}

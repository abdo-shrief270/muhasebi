<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Expenses\Models\ExpenseCategory;
use App\Domain\Expenses\Services\ExpenseCategoryService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Expenses\StoreExpenseCategoryRequest;
use App\Http\Requests\Expenses\UpdateExpenseCategoryRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpenseCategoryController extends Controller
{
    public function __construct(
        private readonly ExpenseCategoryService $expenseCategoryService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $categories = $this->expenseCategoryService->list([
            'search' => $request->query('search'),
            'sort_by' => $request->query('sort_by', 'name'),
            'sort_dir' => $request->query('sort_dir', 'asc'),
            'per_page' => min((int) ($request->query('per_page', 15)), 100),
        ]);

        return $this->success($categories);
    }

    public function store(StoreExpenseCategoryRequest $request): JsonResponse
    {
        $category = $this->expenseCategoryService->create($request->validated());

        return $this->created($category);
    }

    public function show(ExpenseCategory $expenseCategory): JsonResponse
    {
        return $this->success($expenseCategory);
    }

    public function update(UpdateExpenseCategoryRequest $request, ExpenseCategory $expenseCategory): JsonResponse
    {
        $category = $this->expenseCategoryService->update($expenseCategory, $request->validated());

        return $this->success($category);
    }

    public function destroy(ExpenseCategory $expenseCategory): JsonResponse
    {
        $this->expenseCategoryService->delete($expenseCategory);

        return $this->deleted('Expense category deleted successfully.');
    }
}

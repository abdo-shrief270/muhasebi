<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Expenses\Models\ExpenseReport;
use App\Domain\Expenses\Services\ExpenseReportService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Expenses\AddExpensesToReportRequest;
use App\Http\Requests\Expenses\RejectExpenseReportRequest;
use App\Http\Requests\Expenses\StoreExpenseReportRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpenseReportController extends Controller
{
    public function __construct(
        private readonly ExpenseReportService $expenseReportService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $reports = $this->expenseReportService->list([
            'search' => $request->query('search'),
            'status' => $request->query('status'),
            'user_id' => $request->query('user_id'),
            'sort_by' => $request->query('sort_by', 'created_at'),
            'sort_dir' => $request->query('sort_dir', 'desc'),
            'per_page' => min((int) ($request->query('per_page', 15)), 100),
        ]);

        return $this->success($reports);
    }

    public function store(StoreExpenseReportRequest $request): JsonResponse
    {
        $report = $this->expenseReportService->create($request->validated());

        return $this->created($report);
    }

    public function show(ExpenseReport $expenseReport): JsonResponse
    {
        $expenseReport->load(['expenses.category', 'user']);

        return $this->success($expenseReport);
    }

    public function addExpenses(AddExpensesToReportRequest $request, ExpenseReport $expenseReport): JsonResponse
    {
        $expenseReport = $this->expenseReportService->addExpenses($expenseReport, $request->validated());

        return $this->success($expenseReport->load('expenses'));
    }

    public function submit(ExpenseReport $expenseReport): JsonResponse
    {
        $expenseReport = $this->expenseReportService->submit($expenseReport);

        return $this->success($expenseReport);
    }

    public function approve(ExpenseReport $expenseReport): JsonResponse
    {
        $expenseReport = $this->expenseReportService->approve($expenseReport);

        return $this->success($expenseReport);
    }

    public function reject(RejectExpenseReportRequest $request, ExpenseReport $expenseReport): JsonResponse
    {
        $expenseReport = $this->expenseReportService->reject($expenseReport, $request->validated());

        return $this->success($expenseReport);
    }
}

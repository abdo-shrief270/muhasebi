<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Accounting\Models\SavedReport;
use App\Domain\Accounting\Services\CustomReportService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class CustomReportController extends Controller
{
    public function __construct(
        private readonly CustomReportService $reportService,
    ) {}

    /**
     * Execute a custom report from inline config (without saving).
     */
    public function execute(Request $request): JsonResponse
    {
        $data = $request->validate([
            'accounts' => ['required', 'array'],
            'accounts.types' => ['nullable', 'array'],
            'accounts.types.*' => ['string', Rule::in(['asset', 'liability', 'equity', 'revenue', 'expense'])],
            'accounts.codes_from' => ['nullable', 'string', 'max:20'],
            'accounts.codes_to' => ['nullable', 'string', 'max:20'],
            'accounts.ids' => ['nullable', 'array', 'max:50'],
            'accounts.ids.*' => ['integer', Rule::exists('accounts', 'id')->where('tenant_id', app('tenant.id'))],
            'date_range' => ['nullable', 'array'],
            'date_range.from' => ['nullable', 'date'],
            'date_range.to' => ['nullable', 'date'],
            'columns' => ['nullable', 'array'],
            'columns.*' => ['string', Rule::in([
                'code', 'name', 'opening_balance', 'debit', 'credit',
                'closing_balance', 'net_change', 'type',
            ])],
            'grouping' => ['nullable', 'string', Rule::in(['flat', 'parent', 'type'])],
            'include_zero_balances' => ['nullable', 'boolean'],
            'comparison' => ['nullable', 'array'],
            'comparison.enabled' => ['nullable', 'boolean'],
            'comparison.prior_from' => ['nullable', 'date'],
            'comparison.prior_to' => ['nullable', 'date'],
        ]);

        $result = $this->reportService->execute($data);

        return response()->json(['data' => $result]);
    }

    /**
     * List saved report templates.
     */
    public function index(Request $request): JsonResponse
    {
        $reports = $this->reportService->listSaved(
            auth()->id(),
            min((int) ($request->query('per_page', 15)), 100),
        );

        return response()->json($reports);
    }

    /**
     * Save a new report template.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'config' => ['required', 'array'],
            'config.accounts' => ['required', 'array'],
            'is_shared' => ['nullable', 'boolean'],
        ]);

        $report = $this->reportService->save($data);

        return response()->json([
            'data' => $report,
            'message' => 'Report template saved.',
        ], Response::HTTP_CREATED);
    }

    /**
     * Get a saved report template.
     */
    public function show(SavedReport $savedReport): JsonResponse
    {
        return response()->json(['data' => $savedReport->load('creator:id,name')]);
    }

    /**
     * Update a saved report template.
     */
    public function update(Request $request, SavedReport $savedReport): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'config' => ['sometimes', 'array'],
            'is_shared' => ['nullable', 'boolean'],
        ]);

        $report = $this->reportService->update($savedReport, $data);

        return response()->json(['data' => $report]);
    }

    /**
     * Delete a saved report template.
     */
    public function destroy(SavedReport $savedReport): JsonResponse
    {
        $savedReport->delete();

        return response()->json(['message' => 'Report template deleted.']);
    }

    /**
     * Execute a saved report template (with optional date overrides).
     */
    public function run(Request $request, SavedReport $savedReport): JsonResponse
    {
        $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'currency' => 'nullable|string|size:3',
        ]);

        $config = $savedReport->config;

        // Allow overriding dates at runtime
        if ($request->has('from') || $request->has('to')) {
            $config['date_range'] = [
                'from' => $request->query('from', $config['date_range']['from'] ?? null),
                'to' => $request->query('to', $config['date_range']['to'] ?? null),
            ];
        }

        if ($request->has('prior_from') || $request->has('prior_to')) {
            $config['comparison'] = array_merge($config['comparison'] ?? [], [
                'enabled' => true,
                'prior_from' => $request->query('prior_from', $config['comparison']['prior_from'] ?? null),
                'prior_to' => $request->query('prior_to', $config['comparison']['prior_to'] ?? null),
            ]);
        }

        $result = $this->reportService->execute($config);
        $result['report_name'] = $savedReport->name;
        $result['report_name_ar'] = $savedReport->name_ar;

        return response()->json(['data' => $result]);
    }
}

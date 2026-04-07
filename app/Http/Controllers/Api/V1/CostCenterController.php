<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Accounting\Models\CostCenter;
use App\Domain\Accounting\Models\JournalEntryLine;
use App\Http\Controllers\Controller;
use App\Http\Requests\CostCenter\StoreCostCenterRequest;
use App\Http\Requests\CostCenter\UpdateCostCenterRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CostCenterController extends Controller
{
    /**
     * GET /cost-centers
     * Filtered list with capped per_page.
     */
    public function index(Request $request): JsonResponse
    {
        $costCenters = CostCenter::query()
            ->search($request->query('search'))
            ->ofType($request->query('type'))
            ->when($request->boolean('active_only'), fn ($q) => $q->active())
            ->orderBy($request->query('sort_by', 'code'), $request->query('sort_dir', 'asc'))
            ->paginate(min((int) ($request->query('per_page', 15)), 100));

        return $this->success($costCenters);
    }

    /**
     * POST /cost-centers
     */
    public function store(StoreCostCenterRequest $request): JsonResponse
    {
        $costCenter = CostCenter::create($request->validated());

        return $this->created($costCenter);
    }

    /**
     * GET /cost-centers/{cost_center}
     */
    public function show(CostCenter $costCenter): JsonResponse
    {
        $costCenter->load(['parent:id,code,name_ar,name_en', 'children:id,code,name_ar,name_en,type,is_active', 'manager:id,name']);

        return $this->success($costCenter);
    }

    /**
     * PUT /cost-centers/{cost_center}
     */
    public function update(UpdateCostCenterRequest $request, CostCenter $costCenter): JsonResponse
    {
        $costCenter->update($request->validated());

        return $this->success($costCenter);
    }

    /**
     * DELETE /cost-centers/{cost_center}
     */
    public function destroy(CostCenter $costCenter): JsonResponse
    {
        $costCenter->delete();

        return $this->deleted('Cost center deleted successfully.');
    }

    /**
     * GET /cost-centers/{cost_center}/pnl?from=&to=
     * Profit & Loss for a specific cost center.
     */
    public function profitAndLoss(Request $request, CostCenter $costCenter): JsonResponse
    {
        $from = $request->query('from');
        $to = $request->query('to');

        $costCenter->loadMissing('parent:id,code,name_ar');

        // Aggregate journal entry lines allocated to this cost center (matched by code)
        $query = JournalEntryLine::query()
            ->where('cost_center', $costCenter->code)
            ->whereHas('journalEntry', function ($q) use ($from, $to) {
                $q->where('status', 'posted');
                if ($from) {
                    $q->where('date', '>=', $from);
                }
                if ($to) {
                    $q->where('date', '<=', $to);
                }
            });

        $totals = (clone $query)->selectRaw('
            SUM(debit) as total_debit,
            SUM(credit) as total_credit
        ')->first();

        return $this->success([
            'cost_center' => $costCenter,
            'from' => $from,
            'to' => $to,
            'total_debit' => $totals->total_debit ?? 0,
            'total_credit' => $totals->total_credit ?? 0,
            'net' => ($totals->total_debit ?? 0) - ($totals->total_credit ?? 0),
        ]);
    }

    /**
     * GET /cost-centers/reports/cost-analysis?from=&to=
     * Cost analysis across all cost centers.
     */
    public function costAnalysis(Request $request): JsonResponse
    {
        $from = $request->query('from');
        $to = $request->query('to');

        $lines = JournalEntryLine::query()
            ->whereNotNull('cost_center')
            ->whereHas('journalEntry', function ($q) use ($from, $to) {
                $q->where('status', 'posted');
                if ($from) {
                    $q->where('date', '>=', $from);
                }
                if ($to) {
                    $q->where('date', '<=', $to);
                }
            })
            ->selectRaw('cost_center, SUM(debit) as total_debit, SUM(credit) as total_credit')
            ->groupBy('cost_center')
            ->get();

        // Enrich with cost center master data
        $codes = $lines->pluck('cost_center')->filter()->all();
        $centers = CostCenter::whereIn('code', $codes)
            ->get(['id', 'code', 'name_ar', 'name_en', 'type', 'budget_amount'])
            ->keyBy('code');

        $results = $lines->map(fn ($row) => [
            'cost_center' => $centers->get($row->cost_center),
            'code' => $row->cost_center,
            'total_debit' => $row->total_debit,
            'total_credit' => $row->total_credit,
            'net' => $row->total_debit - $row->total_credit,
            'budget_amount' => $centers->get($row->cost_center)?->budget_amount,
            'budget_variance' => $centers->get($row->cost_center)?->budget_amount
                ? $centers->get($row->cost_center)->budget_amount - $row->total_debit
                : null,
        ]);

        return $this->success([
            'from' => $from,
            'to' => $to,
            'cost_centers' => $results,
        ]);
    }

    /**
     * GET /cost-centers/reports/allocation?from=&to=
     * Allocation report showing distribution of costs across accounts.
     */
    public function allocationReport(Request $request): JsonResponse
    {
        $from = $request->query('from');
        $to = $request->query('to');

        $lines = JournalEntryLine::query()
            ->whereNotNull('cost_center')
            ->whereHas('journalEntry', function ($q) use ($from, $to) {
                $q->where('status', 'posted');
                if ($from) {
                    $q->where('date', '>=', $from);
                }
                if ($to) {
                    $q->where('date', '<=', $to);
                }
            })
            ->selectRaw('cost_center, account_id, SUM(debit) as total_debit, SUM(credit) as total_credit')
            ->groupBy('cost_center', 'account_id')
            ->with('account:id,code,name_ar,name_en,type')
            ->get();

        // Enrich with cost center master data
        $codes = $lines->pluck('cost_center')->filter()->unique()->all();
        $centers = CostCenter::whereIn('code', $codes)
            ->get(['id', 'code', 'name_ar', 'name_en', 'type'])
            ->keyBy('code');

        $grouped = $lines->groupBy('cost_center')->map(fn ($rows, $code) => [
            'cost_center' => $centers->get($code),
            'accounts' => $rows->map(fn ($line) => [
                'account' => $line->account,
                'total_debit' => $line->total_debit,
                'total_credit' => $line->total_credit,
            ])->values(),
            'total_debit' => $rows->sum('total_debit'),
            'total_credit' => $rows->sum('total_credit'),
        ])->values();

        return $this->success([
            'from' => $from,
            'to' => $to,
            'allocations' => $grouped,
        ]);
    }
}

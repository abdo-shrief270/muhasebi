<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Accounting\Models\CostCenter;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CostCenterController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $costCenters = CostCenter::query()
            ->search($request->query('search'))
            ->when($request->query('type'), fn ($q, $type) => $q->where('type', $type))
            ->when($request->boolean('active_only'), fn ($q) => $q->active())
            ->paginate(min((int) ($request->query('per_page', 15)), 100));

        return response()->json($costCenters);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50'],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string'],
            'parent_id' => ['nullable', 'integer', 'exists:cost_centers,id'],
            'is_active' => ['boolean'],
            'budget' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $costCenter = CostCenter::create($validated);

        return response()->json($costCenter, Response::HTTP_CREATED);
    }

    public function show(CostCenter $costCenter): JsonResponse
    {
        return response()->json($costCenter->load(['parent', 'children']));
    }

    public function update(Request $request, CostCenter $costCenter): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['sometimes', 'string', 'max:50'],
            'name_ar' => ['sometimes', 'string', 'max:255'],
            'name_en' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', 'string'],
            'parent_id' => ['nullable', 'integer', 'exists:cost_centers,id'],
            'is_active' => ['boolean'],
            'budget' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        if (isset($validated['parent_id']) && $costCenter->wouldCreateCircle($validated['parent_id'])) {
            return response()->json([
                'message' => 'Circular reference detected.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $costCenter->update($validated);

        return response()->json($costCenter);
    }

    public function destroy(CostCenter $costCenter): JsonResponse
    {
        if ($costCenter->hasJournalEntries()) {
            return response()->json([
                'message' => 'Cannot delete cost center with journal entries.',
            ], Response::HTTP_CONFLICT);
        }

        $costCenter->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    public function profitAndLoss(CostCenter $costCenter): JsonResponse
    {
        return response()->json($costCenter->profitAndLoss());
    }

    public function costAnalysis(): JsonResponse
    {
        $costCenters = CostCenter::active()->get()->map(fn (CostCenter $cc) => [
            'id' => $cc->id,
            'code' => $cc->code,
            'name_en' => $cc->name_en,
            ...$cc->costAnalysis(),
        ]);

        return response()->json(['data' => $costCenters]);
    }

    public function allocationReport(): JsonResponse
    {
        $costCenters = CostCenter::active()->get()->map(fn (CostCenter $cc) => [
            'id' => $cc->id,
            'code' => $cc->code,
            'name_en' => $cc->name_en,
            'type' => $cc->type,
            'budget' => $cc->budget,
        ]);

        return response()->json(['data' => $costCenters]);
    }
}

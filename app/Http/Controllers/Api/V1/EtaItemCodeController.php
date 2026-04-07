<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\EInvoice\Models\EtaItemCode;
use App\Domain\EInvoice\Models\EtaItemCodeMapping;
use App\Domain\EInvoice\Services\EtaItemCodeMasterService;
use App\Http\Controllers\Controller;
use App\Http\Requests\EInvoice\BulkAssignItemCodesRequest;
use App\Http\Requests\EInvoice\BulkImportItemCodesRequest;
use App\Http\Requests\EInvoice\StoreEtaItemCodeRequest;
use App\Http\Requests\EInvoice\UpdateEtaItemCodeRequest;
use App\Http\Resources\EtaItemCodeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EtaItemCodeController extends Controller
{
    public function __construct(
        private readonly EtaItemCodeMasterService $masterService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = EtaItemCode::query()
            ->when(
                $request->query('search'),
                fn ($q, $search) => $q->search($search),
            )
            ->when(
                $request->query('active') !== null,
                fn ($q) => filter_var($request->query('active'), FILTER_VALIDATE_BOOLEAN)
                    ? $q->active()
                    : $q->where('is_active', false),
            )
            ->orderBy('item_code');

        return EtaItemCodeResource::collection(
            $query->paginate(min((int) ($request->query('per_page', 15)), 100)),
        );
    }

    public function store(StoreEtaItemCodeRequest $request): EtaItemCodeResource
    {
        $itemCode = EtaItemCode::query()->create([
            'tenant_id' => (int) app('tenant.id'),
            ...$request->validated(),
        ]);

        return new EtaItemCodeResource($itemCode);
    }

    public function show(EtaItemCode $itemCode): EtaItemCodeResource
    {
        return new EtaItemCodeResource($itemCode);
    }

    public function update(UpdateEtaItemCodeRequest $request, EtaItemCode $itemCode): EtaItemCodeResource
    {
        $itemCode->update($request->validated());

        return new EtaItemCodeResource($itemCode->refresh());
    }

    public function destroy(EtaItemCode $itemCode): JsonResponse
    {
        $itemCode->delete();

        return response()->json([
            'message' => 'Item code deleted successfully.',
        ]);
    }

    // ──────────────────────────────────────
    // Item Code Master methods
    // ──────────────────────────────────────

    public function bulkAssign(BulkAssignItemCodesRequest $request): JsonResponse
    {
        $created = $this->masterService->bulkAssign($request->validated('mappings'));

        return response()->json([
            'message' => "Successfully assigned {$created} item code mapping(s).",
            'created' => $created,
        ]);
    }

    public function bulkImport(BulkImportItemCodesRequest $request): JsonResponse
    {
        $result = $this->masterService->bulkImport($request->validated('codes'));

        return response()->json([
            'message' => "Import complete: {$result['created']} created, {$result['updated']} updated.",
            ...$result,
        ]);
    }

    public function autoAssign(): JsonResponse
    {
        $result = $this->masterService->autoAssign((int) app('tenant.id'));

        return response()->json([
            'message' => "Auto-assign complete: {$result['matched']} of {$result['total_lines']} lines matched.",
            ...$result,
        ]);
    }

    public function usageReport(Request $request): JsonResponse
    {
        $filters = $request->only(['from', 'to']);

        return response()->json($this->masterService->usageReport($filters));
    }

    public function unmappedLines(Request $request): JsonResponse
    {
        $filters = $request->only(['from', 'to', 'client_id', 'per_page']);

        return response()->json($this->masterService->unmappedLines($filters));
    }

    public function suggestCode(Request $request): JsonResponse
    {
        $request->validate([
            'description' => ['required', 'string', 'min:2', 'max:500'],
        ]);

        $suggestions = $this->masterService->suggestCode($request->query('description'));

        return response()->json(['suggestions' => $suggestions]);
    }

    public function mappings(Request $request): AnonymousResourceCollection|JsonResponse
    {
        $mappings = EtaItemCodeMapping::query()
            ->where('tenant_id', (int) app('tenant.id'))
            ->with(['etaItemCode', 'product'])
            ->ordered()
            ->paginate(min((int) ($request->query('per_page', 15)), 100));

        return response()->json($mappings);
    }

    public function createMapping(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'eta_item_code_id' => ['required', 'integer', 'exists:eta_item_codes,id'],
            'product_id' => ['nullable', 'integer'],
            'description_pattern' => ['nullable', 'string', 'max:255'],
            'priority' => ['nullable', 'integer', 'min:0'],
        ]);

        $mapping = EtaItemCodeMapping::query()->create([
            'tenant_id' => (int) app('tenant.id'),
            ...$validated,
            'assignment_source' => 'manual',
        ]);

        return response()->json($mapping->load(['etaItemCode', 'product']), 201);
    }

    public function deleteMapping(EtaItemCodeMapping $mapping): JsonResponse
    {
        $mapping->delete();

        return response()->json([
            'message' => 'Mapping deleted successfully.',
        ]);
    }
}

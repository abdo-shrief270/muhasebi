<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\EInvoice\Models\EtaItemCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\EInvoice\StoreEtaItemCodeRequest;
use App\Http\Requests\EInvoice\UpdateEtaItemCodeRequest;
use App\Http\Resources\EtaItemCodeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class EtaItemCodeController extends Controller
{
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
        ], Response::HTTP_OK);
    }
}

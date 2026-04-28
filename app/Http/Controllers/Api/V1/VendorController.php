<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\AccountsPayable\Models\Vendor;
use App\Domain\AccountsPayable\Services\VendorService;
use App\Http\Controllers\Controller;
use App\Http\Requests\AccountsPayable\StoreVendorRequest;
use App\Http\Requests\AccountsPayable\UpdateVendorRequest;
use App\Http\Resources\VendorResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class VendorController extends Controller
{
    public function __construct(
        private readonly VendorService $vendorService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $vendors = $this->vendorService->list([
            'search' => $request->query('search'),
            'is_active' => $request->has('is_active') ? filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN) : null,
            'sort_by' => $request->query('sort_by'),
            'sort_dir' => $request->query('sort_dir', 'asc'),
            'per_page' => min((int) ($request->query('per_page', 15)), 100),
        ]);

        return VendorResource::collection($vendors);
    }

    public function store(StoreVendorRequest $request): JsonResponse
    {
        $vendor = $this->vendorService->create($request->validated());

        return (new VendorResource($vendor))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Vendor $vendor): VendorResource
    {
        $vendor->loadCount('bills');

        // Enrich with the financial summary the SPA detail page renders.
        $summary = $this->vendorService->vendorSummary($vendor);
        $vendor->setAttribute('balance', $summary['balance']);
        $vendor->setAttribute('open_bills_count', $summary['open_bills_count']);
        $vendor->setAttribute('aging_buckets', $summary['aging_buckets']);
        $vendor->setAttribute('last_payment_at', $summary['last_payment_at']);

        return new VendorResource($vendor);
    }

    public function update(UpdateVendorRequest $request, Vendor $vendor): VendorResource
    {
        $vendor = $this->vendorService->update($vendor, $request->validated());

        return new VendorResource($vendor);
    }

    public function destroy(Vendor $vendor): JsonResponse
    {
        $this->vendorService->delete($vendor);

        return response()->json([
            'message' => __('messages.success.deleted'),
        ]);
    }

    public function statement(Request $request, Vendor $vendor): JsonResponse
    {
        // Accept either short (from/to) or long (from_date/to_date) query
        // params — the SPA sends the long variant.
        $statement = $this->vendorService->statement(
            $vendor,
            $request->query('from') ?? $request->query('from_date'),
            $request->query('to') ?? $request->query('to_date'),
        );

        return response()->json(['data' => $statement]);
    }

    public function aging(Request $request): JsonResponse
    {
        $report = $this->vendorService->agingReport([
            'vendor_id' => $request->query('vendor_id'),
            'as_of' => $request->query('as_of'),
        ]);

        return response()->json(['data' => $report]);
    }
}

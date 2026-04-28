<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\AccountsPayable\Models\Vendor;
use App\Domain\AccountsPayable\Models\VendorProduct;
use App\Domain\AccountsPayable\Services\VendorProductService;
use App\Http\Controllers\Controller;
use App\Http\Requests\VendorProduct\StoreVendorProductRequest;
use App\Http\Requests\VendorProduct\UpdateVendorProductRequest;
use App\Http\Resources\VendorProductResource;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-vendor billable items. Mirrors ClientProductController.
 *
 * Routes are bound under /vendors/{vendor}/products via apiResource scoping
 * — the {vendor} and {product} params come pre-tenant-scoped through
 * BelongsToTenant; trying to access another tenant's row 404s.
 */
class VendorProductController extends Controller
{
    public function __construct(
        private readonly VendorProductService $service,
    ) {}

    public function index(Request $request, Vendor $vendor): AnonymousResourceCollection
    {
        $products = $this->service->listForVendor($vendor, [
            'search' => $request->query('search'),
            'is_active' => $request->has('is_active')
                ? filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                : null,
            'sort_by' => $request->query('sort_by', 'name'),
            'sort_dir' => $request->query('sort_dir', 'asc'),
            'per_page' => (int) ($request->query('per_page', 25)),
        ]);

        return VendorProductResource::collection($products);
    }

    public function store(StoreVendorProductRequest $request, Vendor $vendor): VendorProductResource
    {
        try {
            $product = $this->service->create($vendor, $request->validated());
        } catch (UniqueConstraintViolationException) {
            // Hits the unique(tenant_id, vendor_id, name) index — surface as
            // a 422 instead of bubbling the SQL error out.
            throw ValidationException::withMessages([
                'name' => [__('messages.error.duplicate_name')],
            ]);
        }

        return new VendorProductResource($product);
    }

    public function show(Vendor $vendor, VendorProduct $product): VendorProductResource
    {
        $this->ensureBelongsToVendor($vendor, $product);

        return new VendorProductResource($product->load('defaultAccount:id,code,name_ar,name_en'));
    }

    public function update(
        UpdateVendorProductRequest $request,
        Vendor $vendor,
        VendorProduct $product,
    ): VendorProductResource {
        $this->ensureBelongsToVendor($vendor, $product);

        try {
            $this->service->update($product, $request->validated());
        } catch (UniqueConstraintViolationException) {
            // Race-condition fallback for the pre-flight check inside the
            // service: two concurrent renames could both pass the SELECT
            // and only the second would hit the unique index on UPDATE.
            throw ValidationException::withMessages([
                'name' => [__('messages.error.duplicate_name')],
            ]);
        }

        return new VendorProductResource($product);
    }

    public function destroy(Vendor $vendor, VendorProduct $product): JsonResponse
    {
        $this->ensureBelongsToVendor($vendor, $product);
        $this->service->delete($product);

        return response()->json(['message' => __('messages.success.deleted')]);
    }

    /**
     * Tenant-wide catalog — flattens vendor products across all vendors.
     * Read-only; mutations always go through the per-vendor routes so the
     * audit trail and route-model binding stay sensible. Mirrors the AR-side
     * /catalog endpoint exposed by ClientProductController.
     */
    public function catalog(Request $request): AnonymousResourceCollection
    {
        $products = $this->service->listCatalog([
            'search' => $request->query('search'),
            'vendor_id' => $request->query('vendor_id'),
            'is_active' => $request->has('is_active')
                ? filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                : null,
            'sort_by' => $request->query('sort_by', 'name'),
            'sort_dir' => $request->query('sort_dir', 'asc'),
            'per_page' => (int) ($request->query('per_page', 25)),
        ]);

        return VendorProductResource::collection($products);
    }

    /**
     * Defense-in-depth: route-model binding will already 404 if the product
     * doesn't exist in the current tenant, but if a caller passes a
     * mismatched (vendor, product) pair we still want a clean 404.
     */
    private function ensureBelongsToVendor(Vendor $vendor, VendorProduct $product): void
    {
        if ($product->vendor_id !== $vendor->id) {
            abort(Response::HTTP_NOT_FOUND);
        }
    }
}

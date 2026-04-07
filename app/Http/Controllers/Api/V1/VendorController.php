<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\AP\Models\Vendor;
use App\Domain\AP\Services\VendorService;
use App\Http\Controllers\Controller;
use App\Http\Requests\AP\StoreVendorRequest;
use App\Http\Requests\AP\UpdateVendorRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VendorController extends Controller
{
    public function __construct(
        private readonly VendorService $vendorService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $vendors = $this->vendorService->list([
            'search' => $request->query('search'),
            'is_active' => $request->has('is_active') ? filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN) : null,
            'sort_by' => $request->query('sort_by', 'name'),
            'sort_dir' => $request->query('sort_dir', 'asc'),
            'per_page' => min((int) ($request->query('per_page', 15)), 100),
        ]);

        return $this->success($vendors);
    }

    public function store(StoreVendorRequest $request): JsonResponse
    {
        $vendor = $this->vendorService->create($request->validated());

        return $this->created($vendor);
    }

    public function show(Vendor $vendor): JsonResponse
    {
        $vendor->loadCount('bills');

        return $this->success($vendor);
    }

    public function update(UpdateVendorRequest $request, Vendor $vendor): JsonResponse
    {
        $vendor = $this->vendorService->update($vendor, $request->validated());

        return $this->success($vendor);
    }

    public function destroy(Vendor $vendor): JsonResponse
    {
        $this->vendorService->delete($vendor);

        return $this->deleted('Vendor deleted successfully.');
    }

    public function statement(Request $request, Vendor $vendor): JsonResponse
    {
        $statement = $this->vendorService->statement($vendor, [
            'from' => $request->query('from'),
            'to' => $request->query('to'),
        ]);

        return $this->success($statement);
    }

    public function aging(Request $request): JsonResponse
    {
        $report = $this->vendorService->agingReport([
            'vendor_id' => $request->query('vendor_id'),
            'as_of' => $request->query('as_of'),
        ]);

        return $this->success($report);
    }
}

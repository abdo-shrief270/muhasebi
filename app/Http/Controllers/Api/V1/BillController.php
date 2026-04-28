<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\AccountsPayable\Models\Bill;
use App\Domain\AccountsPayable\Services\BillService;
use App\Http\Controllers\Controller;
use App\Http\Requests\AccountsPayable\StoreBillRequest;
use App\Http\Requests\AccountsPayable\UpdateBillRequest;
use App\Http\Resources\BillResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class BillController extends Controller
{
    public function __construct(
        private readonly BillService $billService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $bills = $this->billService->list([
            'search' => $request->query('search'),
            'status' => $request->query('status'),
            'vendor_id' => $request->query('vendor_id'),
            // Accept both `from`/`to` (legacy) and the SPA's `from_date`/`to_date`.
            'date_from' => $request->query('from') ?? $request->query('from_date'),
            'date_to' => $request->query('to') ?? $request->query('to_date'),
            'due_from' => $request->query('due_from'),
            'due_to' => $request->query('due_to'),
            'sort_by' => $request->query('sort_by'),
            'sort_dir' => $request->query('sort_dir', 'desc'),
            'per_page' => min((int) ($request->query('per_page', 15)), 100),
        ]);

        return BillResource::collection($bills);
    }

    public function store(StoreBillRequest $request): JsonResponse
    {
        $bill = $this->billService->create($request->validated());

        return (new BillResource($bill->load(['vendor', 'lines'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Bill $bill): BillResource
    {
        $bill->load(['vendor', 'lines.account', 'payments']);

        return new BillResource($bill);
    }

    public function update(UpdateBillRequest $request, Bill $bill): BillResource
    {
        $bill = $this->billService->update($bill, $request->validated());

        return new BillResource($bill->load(['vendor', 'lines.account']));
    }

    public function destroy(Bill $bill): JsonResponse
    {
        $this->billService->delete($bill);

        return response()->json([
            'message' => __('messages.success.deleted'),
        ]);
    }

    public function approve(Bill $bill): BillResource
    {
        $bill = $this->billService->approve($bill);

        return new BillResource($bill->load(['vendor', 'lines.account']));
    }

    public function cancel(Bill $bill): BillResource
    {
        $bill = $this->billService->cancel($bill);

        return new BillResource($bill->load(['vendor', 'lines.account']));
    }
}

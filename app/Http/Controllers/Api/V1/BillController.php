<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\AccountsPayable\Models\Bill;
use App\Domain\AccountsPayable\Services\BillService;
use App\Http\Controllers\Controller;
use App\Http\Requests\AccountsPayable\StoreBillRequest;
use App\Http\Requests\AccountsPayable\UpdateBillRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillController extends Controller
{
    public function __construct(
        private readonly BillService $billService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $bills = $this->billService->list([
            'search' => $request->query('search'),
            'status' => $request->query('status'),
            'vendor_id' => $request->query('vendor_id'),
            'date_from' => $request->query('from'),
            'date_to' => $request->query('to'),
            'per_page' => min((int) ($request->query('per_page', 15)), 100),
        ]);

        return $this->success($bills);
    }

    public function store(StoreBillRequest $request): JsonResponse
    {
        $bill = $this->billService->create($request->validated());

        return $this->created($bill->load(['vendor', 'lines']));
    }

    public function show(Bill $bill): JsonResponse
    {
        $bill->load(['vendor', 'lines.account', 'payments']);

        return $this->success($bill);
    }

    public function update(UpdateBillRequest $request, Bill $bill): JsonResponse
    {
        $bill = $this->billService->update($bill, $request->validated());

        return $this->success($bill->load(['vendor', 'lines']));
    }

    public function destroy(Bill $bill): JsonResponse
    {
        $this->billService->delete($bill);

        return $this->deleted('Bill deleted successfully.');
    }

    public function approve(Bill $bill): JsonResponse
    {
        $bill = $this->billService->approve($bill);

        return $this->success($bill);
    }

    public function cancel(Bill $bill): JsonResponse
    {
        $bill = $this->billService->cancel($bill);

        return $this->success($bill);
    }
}

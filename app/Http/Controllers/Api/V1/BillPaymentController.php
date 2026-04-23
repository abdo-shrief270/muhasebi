<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\AccountsPayable\Models\Bill;
use App\Domain\AccountsPayable\Models\BillPayment;
use App\Domain\AccountsPayable\Services\BillPaymentService;
use App\Http\Controllers\Controller;
use App\Http\Requests\AccountsPayable\StoreBillPaymentRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillPaymentController extends Controller
{
    public function __construct(
        private readonly BillPaymentService $billPaymentService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $payments = $this->billPaymentService->list([
            'vendor_id' => $request->query('vendor_id'),
            'date_from' => $request->query('from'),
            'date_to' => $request->query('to'),
            'per_page' => min((int) ($request->query('per_page', 15)), 100),
        ]);

        return $this->success($payments);
    }

    public function store(StoreBillPaymentRequest $request, Bill $bill): JsonResponse
    {
        $payment = $this->billPaymentService->record($bill, $request->validated());

        return $this->created($payment);
    }

    public function void(BillPayment $billPayment): JsonResponse
    {
        $this->billPaymentService->void($billPayment);

        return $this->success(message: 'Payment voided successfully.');
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Tax\Models\TaxAdjustment;
use App\Domain\Tax\Models\TaxReturn;
use App\Domain\Tax\Services\TaxReturnService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tax\CalculateCorporateTaxRequest;
use App\Http\Requests\Tax\CalculateVatReturnRequest;
use App\Http\Requests\Tax\RecordTaxPaymentRequest;
use App\Http\Requests\Tax\StoreTaxAdjustmentRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaxReturnController extends Controller
{
    public function __construct(
        private readonly TaxReturnService $taxReturnService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $returns = $this->taxReturnService->list([
            'type' => $request->query('type'),
            'status' => $request->query('status'),
            'fiscal_year_id' => $request->query('fiscal_year_id'),
            'per_page' => min((int) ($request->query('per_page', 15)), 100),
        ]);

        return $this->success($returns);
    }

    public function calculateCorporateTax(CalculateCorporateTaxRequest $request): JsonResponse
    {
        $taxReturn = $this->taxReturnService->calculateCorporateTax($request->validated());

        return $this->created($taxReturn);
    }

    public function calculateVatReturn(CalculateVatReturnRequest $request): JsonResponse
    {
        $taxReturn = $this->taxReturnService->calculateVatReturn($request->validated());

        return $this->created($taxReturn);
    }

    public function show(TaxReturn $taxReturn): JsonResponse
    {
        $taxReturn->load(['fiscalYear', 'payments', 'adjustments']);

        return $this->success($taxReturn);
    }

    public function file(TaxReturn $taxReturn): JsonResponse
    {
        $taxReturn = $this->taxReturnService->file($taxReturn);

        return $this->success($taxReturn);
    }

    public function recordPayment(RecordTaxPaymentRequest $request, TaxReturn $taxReturn): JsonResponse
    {
        $payment = $this->taxReturnService->recordPayment($taxReturn, $request->validated());

        return $this->created($payment);
    }

    public function adjustments(FiscalYear $fiscalYear): JsonResponse
    {
        $adjustments = $this->taxReturnService->getAdjustments($fiscalYear);

        return $this->success($adjustments);
    }

    public function storeAdjustment(StoreTaxAdjustmentRequest $request): JsonResponse
    {
        $adjustment = $this->taxReturnService->storeAdjustment($request->validated());

        return $this->created($adjustment);
    }

    public function destroyAdjustment(TaxAdjustment $adjustment): JsonResponse
    {
        $this->taxReturnService->deleteAdjustment($adjustment);

        return $this->deleted('تم حذف التسوية بنجاح.');
    }
}

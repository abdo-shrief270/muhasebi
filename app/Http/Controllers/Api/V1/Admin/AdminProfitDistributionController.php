<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Investor\Models\Investor;
use App\Domain\Investor\Models\ProfitDistribution;
use App\Domain\Investor\Services\ProfitDistributionService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CalculateDistributionRequest;
use App\Http\Resources\ProfitDistributionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class AdminProfitDistributionController extends Controller
{
    public function __construct(
        private readonly ProfitDistributionService $distributionService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return ProfitDistributionResource::collection(
            $this->distributionService->list([
                'investor_id' => $request->query('investor_id'),
                'tenant_id' => $request->query('tenant_id'),
                'month' => $request->query('month'),
                'year' => $request->query('year'),
                'status' => $request->query('status'),
                'per_page' => min((int) ($request->query('per_page', 15)), 100),
            ]),
        );
    }

    public function calculate(CalculateDistributionRequest $request): JsonResponse
    {
        $expensesPerTenant = collect($request->validated('expenses', []))
            ->pluck('amount', 'tenant_id')
            ->map(fn ($v) => (float) $v)
            ->toArray();

        $distributions = $this->distributionService->calculate(
            month: $request->validated('month'),
            year: $request->validated('year'),
            expensesPerTenant: $expensesPerTenant,
        );

        return response()->json([
            'data' => ProfitDistributionResource::collection($distributions),
            'count' => $distributions->count(),
        ], Response::HTTP_CREATED);
    }

    public function show(ProfitDistribution $distribution): ProfitDistributionResource
    {
        return new ProfitDistributionResource($distribution->load(['investor', 'tenant']));
    }

    public function approve(ProfitDistribution $distribution): ProfitDistributionResource
    {
        return new ProfitDistributionResource(
            $this->distributionService->approve($distribution),
        );
    }

    public function markPaid(ProfitDistribution $distribution): ProfitDistributionResource
    {
        return new ProfitDistributionResource(
            $this->distributionService->markPaid($distribution),
        );
    }

    public function destroy(ProfitDistribution $distribution): JsonResponse
    {
        $this->distributionService->delete($distribution);

        return response()->json(['message' => 'Distribution deleted successfully.']);
    }

    public function payslip(Investor $investor, Request $request): Response
    {
        $request->validate([
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'year' => ['required', 'integer'],
        ]);

        return $this->distributionService->generatePayslip(
            $investor,
            (int) $request->query('month'),
            (int) $request->query('year'),
        );
    }
}

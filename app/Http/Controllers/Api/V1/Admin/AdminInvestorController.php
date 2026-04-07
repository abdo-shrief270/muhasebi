<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Investor\Models\Investor;
use App\Domain\Investor\Services\InvestorService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SetInvestorTenantShareRequest;
use App\Http\Requests\Admin\StoreInvestorRequest;
use App\Http\Requests\Admin\UpdateInvestorRequest;
use App\Http\Resources\InvestorResource;
use App\Http\Resources\InvestorTenantShareResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminInvestorController extends Controller
{
    public function __construct(
        private readonly InvestorService $investorService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return InvestorResource::collection(
            $this->investorService->list([
                'search' => $request->query('search'),
                'is_active' => $request->query('is_active') !== null
                    ? filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN)
                    : null,
                'per_page' => min((int) ($request->query('per_page', 15)), 100),
            ]),
        );
    }

    public function store(StoreInvestorRequest $request): InvestorResource
    {
        return new InvestorResource(
            $this->investorService->create($request->validated()),
        );
    }

    public function show(Investor $investor): InvestorResource
    {
        return new InvestorResource($investor->load('tenantShares.tenant'));
    }

    public function update(UpdateInvestorRequest $request, Investor $investor): InvestorResource
    {
        return new InvestorResource(
            $this->investorService->update($investor, $request->validated()),
        );
    }

    public function destroy(Investor $investor): JsonResponse
    {
        $this->investorService->delete($investor);

        return response()->json(['message' => 'Investor deleted successfully.']);
    }

    // ──────────────────────────────────────
    // Tenant Shares
    // ──────────────────────────────────────

    public function shares(Investor $investor): AnonymousResourceCollection
    {
        return InvestorTenantShareResource::collection(
            $this->investorService->getShares($investor),
        );
    }

    public function setShare(SetInvestorTenantShareRequest $request, Investor $investor): InvestorTenantShareResource
    {
        return new InvestorTenantShareResource(
            $this->investorService->setShare(
                $investor,
                $request->validated('tenant_id'),
                (float) $request->validated('ownership_percentage'),
            ),
        );
    }

    public function removeShare(Investor $investor, int $tenant): JsonResponse
    {
        $this->investorService->removeShare($investor, $tenant);

        return response()->json(['message' => 'Share removed successfully.']);
    }
}

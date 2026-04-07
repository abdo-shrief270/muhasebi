<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\FixedAssets\Models\FixedAsset;
use App\Domain\FixedAssets\Services\AssetService;
use App\Domain\FixedAssets\Services\DepreciationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\FixedAssets\StoreFixedAssetRequest;
use App\Http\Requests\FixedAssets\UpdateFixedAssetRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FixedAssetController extends Controller
{
    public function __construct(
        private readonly AssetService $assetService,
        private readonly DepreciationService $depreciationService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $assets = $this->assetService->list([
            'status' => $request->query('status'),
            'category_id' => $request->query('category_id'),
            'search' => $request->query('search'),
            'sort_by' => $request->query('sort_by', 'name'),
            'sort_dir' => $request->query('sort_dir', 'asc'),
            'per_page' => min((int) ($request->query('per_page', 15)), 100),
        ]);

        return $this->success($assets);
    }

    public function store(StoreFixedAssetRequest $request): JsonResponse
    {
        $asset = $this->assetService->create($request->validated());

        return $this->created($asset);
    }

    public function show(FixedAsset $fixedAsset): JsonResponse
    {
        $asset = $this->assetService->show($fixedAsset);

        return $this->success($asset);
    }

    public function update(UpdateFixedAssetRequest $request, FixedAsset $fixedAsset): JsonResponse
    {
        $asset = $this->assetService->update($fixedAsset, $request->validated());

        return $this->success($asset);
    }

    public function destroy(FixedAsset $fixedAsset): JsonResponse
    {
        $this->assetService->delete($fixedAsset);

        return $this->deleted('Fixed asset deleted successfully.');
    }

    public function schedule(FixedAsset $fixedAsset): JsonResponse
    {
        $schedule = $this->depreciationService->schedule($fixedAsset);

        return $this->success($schedule);
    }

    public function register(Request $request): JsonResponse
    {
        $register = $this->assetService->assetRegister($request->query());

        return $this->success($register);
    }

    public function rollForward(Request $request): JsonResponse
    {
        $report = $this->assetService->rollForward([
            'from' => $request->query('from'),
            'to' => $request->query('to'),
        ]);

        return $this->success($report);
    }

    public function depreciate(): JsonResponse
    {
        $result = $this->depreciationService->runMonthly(tenant());

        return $this->success($result);
    }
}

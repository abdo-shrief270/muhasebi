<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\FixedAssets\Models\AssetCategory;
use App\Domain\FixedAssets\Services\AssetCategoryService;
use App\Http\Controllers\Controller;
use App\Http\Requests\FixedAssets\StoreAssetCategoryRequest;
use App\Http\Requests\FixedAssets\UpdateAssetCategoryRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssetCategoryController extends Controller
{
    public function __construct(
        private readonly AssetCategoryService $assetCategoryService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $categories = $this->assetCategoryService->list([
            'search' => $request->query('search'),
            'sort_by' => $request->query('sort_by', 'name'),
            'sort_dir' => $request->query('sort_dir', 'asc'),
            'per_page' => min((int) ($request->query('per_page', 15)), 100),
        ]);

        return $this->success($categories);
    }

    public function store(StoreAssetCategoryRequest $request): JsonResponse
    {
        $category = $this->assetCategoryService->create($request->validated());

        return $this->created($category);
    }

    public function show(AssetCategory $assetCategory): JsonResponse
    {
        return $this->success($assetCategory);
    }

    public function update(UpdateAssetCategoryRequest $request, AssetCategory $assetCategory): JsonResponse
    {
        $category = $this->assetCategoryService->update($assetCategory, $request->validated());

        return $this->success($category);
    }

    public function destroy(AssetCategory $assetCategory): JsonResponse
    {
        $this->assetCategoryService->delete($assetCategory);

        return $this->deleted('Asset category deleted successfully.');
    }
}

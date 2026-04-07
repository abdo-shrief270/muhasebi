<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\FixedAssets\Services\AssetDisposalService;
use App\Http\Controllers\Controller;
use App\Http\Requests\FixedAssets\DisposeAssetRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssetDisposalController extends Controller
{
    public function __construct(
        private readonly AssetDisposalService $assetDisposalService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $disposals = $this->assetDisposalService->list([
            'search' => $request->query('search'),
            'asset_id' => $request->query('asset_id'),
            'sort_by' => $request->query('sort_by', 'disposed_at'),
            'sort_dir' => $request->query('sort_dir', 'desc'),
            'per_page' => min((int) ($request->query('per_page', 15)), 100),
        ]);

        return $this->success($disposals);
    }

    public function store(DisposeAssetRequest $request): JsonResponse
    {
        $disposal = $this->assetDisposalService->dispose($request->validated());

        return $this->created($disposal);
    }
}

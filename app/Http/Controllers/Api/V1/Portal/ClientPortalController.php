<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Portal;

use App\Domain\ClientPortal\Services\ClientPortalService;
use App\Http\Controllers\Controller;
use App\Http\Resources\ClientResource;
use Illuminate\Http\JsonResponse;

class ClientPortalController extends Controller
{
    public function __construct(
        private readonly ClientPortalService $portalService,
    ) {}

    public function dashboard(): JsonResponse
    {
        return response()->json([
            'data' => $this->portalService->dashboard(
                app('portal.client'),
                (int) auth()->id(),
            ),
        ]);
    }

    public function profile(): ClientResource
    {
        return new ClientResource(
            $this->portalService->profile(app('portal.client')),
        );
    }
}

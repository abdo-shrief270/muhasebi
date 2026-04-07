<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenant\Services\LandingPageService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\UpdateLandingPageRequest;
use App\Http\Resources\LandingPageSettingsResource;

class LandingPageSettingsController extends Controller
{
    public function __construct(
        private readonly LandingPageService $service,
    ) {}

    public function show(): LandingPageSettingsResource
    {
        return new LandingPageSettingsResource(app('tenant'));
    }

    public function update(UpdateLandingPageRequest $request): LandingPageSettingsResource
    {
        $tenant = $this->service->updateBranding(app('tenant'), $request->validated());

        return new LandingPageSettingsResource($tenant);
    }
}

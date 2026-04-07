<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Services\LandingPageService;
use Illuminate\View\View;

class LandingPageController extends Controller
{
    public function __construct(
        private readonly LandingPageService $service,
    ) {}

    public function __invoke(Tenant $tenant): View
    {
        if (! $tenant->hasActiveLandingPage()) {
            abort(404);
        }

        $data = $this->service->getPageData($tenant);

        return view('landing.index', $data);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Accounting\Models\FiscalPeriod;
use App\Domain\Accounting\Services\FiscalPeriodService;
use App\Http\Controllers\Controller;
use App\Http\Resources\FiscalPeriodResource;

class FiscalPeriodController extends Controller
{
    public function __construct(
        private readonly FiscalPeriodService $fiscalPeriodService,
    ) {}

    public function close(FiscalPeriod $fiscalPeriod): FiscalPeriodResource
    {
        $period = $this->fiscalPeriodService->closePeriod($fiscalPeriod);

        return new FiscalPeriodResource($period);
    }

    public function reopen(FiscalPeriod $fiscalPeriod): FiscalPeriodResource
    {
        $period = $this->fiscalPeriodService->reopenPeriod($fiscalPeriod);

        return new FiscalPeriodResource($period);
    }
}

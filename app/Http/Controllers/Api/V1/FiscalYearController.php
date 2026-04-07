<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Accounting\Services\FiscalPeriodService;
use App\Http\Controllers\Controller;
use App\Http\Requests\FiscalYear\StoreFiscalYearRequest;
use App\Http\Resources\FiscalYearResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class FiscalYearController extends Controller
{
    public function __construct(
        private readonly FiscalPeriodService $fiscalPeriodService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $years = $this->fiscalPeriodService->listYears([
            'search' => $request->query('search'),
            'is_closed' => $request->has('is_closed') ? filter_var($request->query('is_closed'), FILTER_VALIDATE_BOOLEAN) : null,
            'sort_by' => $request->query('sort_by', 'start_date'),
            'sort_dir' => $request->query('sort_dir', 'desc'),
            'per_page' => $request->query('per_page', 15),
        ]);

        return FiscalYearResource::collection($years);
    }

    public function store(StoreFiscalYearRequest $request): JsonResponse
    {
        $year = $this->fiscalPeriodService->createYear($request->validated());

        return (new FiscalYearResource($year))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(FiscalYear $fiscalYear): FiscalYearResource
    {
        return new FiscalYearResource($this->fiscalPeriodService->showYear($fiscalYear));
    }
}

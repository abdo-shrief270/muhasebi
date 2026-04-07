<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Accounting\Services\AnomalyDetectionService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnomalyDetectionController extends Controller
{
    public function __construct(
        private readonly AnomalyDetectionService $anomalyDetectionService,
    ) {}

    public function detectAll(Request $request): JsonResponse
    {
        $filters = $this->extractFilters($request);

        return $this->success(
            $this->anomalyDetectionService->detectAll($filters),
            'Anomaly detection complete',
        );
    }

    public function duplicates(Request $request): JsonResponse
    {
        $filters = $this->extractFilters($request);

        return $this->success(
            $this->anomalyDetectionService->duplicateInvoices($filters),
            'Duplicate invoice detection complete',
        );
    }

    public function unusualAmounts(Request $request): JsonResponse
    {
        $filters = $this->extractFilters($request);

        return $this->success(
            $this->anomalyDetectionService->unusualAmounts($filters),
            'Unusual amount detection complete',
        );
    }

    public function missingSequences(Request $request): JsonResponse
    {
        $filters = $this->extractFilters($request);

        return $this->success(
            $this->anomalyDetectionService->missingSequences($filters),
            'Missing sequence detection complete',
        );
    }

    public function weekendEntries(Request $request): JsonResponse
    {
        $filters = $this->extractFilters($request);

        return $this->success(
            $this->anomalyDetectionService->weekendEntries($filters),
            'Weekend entry detection complete',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function extractFilters(Request $request): array
    {
        return array_filter([
            'from' => $request->query('from'),
            'to' => $request->query('to'),
        ]);
    }
}

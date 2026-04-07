<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Currency\Models\FxRevaluation;
use App\Domain\Currency\Services\FxRevaluationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class FxRevaluationController extends Controller
{
    public function __construct(
        private readonly FxRevaluationService $fxRevaluationService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $revaluations = $this->fxRevaluationService->list([
            'status' => $request->query('status'),
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'sort_by' => $request->query('sort_by', 'revaluation_date'),
            'sort_dir' => $request->query('sort_dir', 'desc'),
            'per_page' => min((int) ($request->query('per_page', 15)), 100),
        ]);

        return response()->json($revaluations);
    }

    public function calculate(Request $request): JsonResponse
    {
        $request->validate([
            'date' => ['required', 'date'],
        ]);

        $revaluation = $this->fxRevaluationService->calculate($request->input('date'));

        return response()->json([
            'data' => $revaluation,
        ], Response::HTTP_CREATED);
    }

    public function show(FxRevaluation $fxRevaluation): JsonResponse
    {
        $fxRevaluation->load(['lines.account', 'journalEntry', 'createdByUser']);

        return response()->json([
            'data' => $fxRevaluation,
        ]);
    }

    public function post(FxRevaluation $fxRevaluation): JsonResponse
    {
        $revaluation = $this->fxRevaluationService->post($fxRevaluation);

        return response()->json([
            'data' => $revaluation,
        ]);
    }

    public function reverse(FxRevaluation $fxRevaluation): JsonResponse
    {
        $revaluation = $this->fxRevaluationService->reverse($fxRevaluation);

        return response()->json([
            'data' => $revaluation,
        ]);
    }
}

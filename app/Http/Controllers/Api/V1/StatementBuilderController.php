<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Accounting\Models\StatementTemplate;
use App\Domain\Accounting\Services\StatementBuilderService;
use App\Http\Controllers\Controller;
use App\Http\Requests\StatementTemplate\StoreStatementTemplateRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class StatementBuilderController extends Controller
{
    public function __construct(
        private readonly StatementBuilderService $service,
    ) {}

    // ──────────────────────────────────────
    // Template CRUD
    // ──────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $templates = $this->service->list([
            'type' => $request->query('type'),
            'per_page' => $request->query('per_page', 15),
        ]);

        return response()->json($templates);
    }

    public function store(StoreStatementTemplateRequest $request): JsonResponse
    {
        $template = $this->service->create($request->validated());

        return response()->json([
            'data' => $template,
            'message' => 'Statement template created.',
        ], Response::HTTP_CREATED);
    }

    public function show(StatementTemplate $statementTemplate): JsonResponse
    {
        return response()->json([
            'data' => $statementTemplate->load('creator:id,name'),
        ]);
    }

    public function update(StoreStatementTemplateRequest $request, StatementTemplate $statementTemplate): JsonResponse
    {
        $template = $this->service->update($statementTemplate, $request->validated());

        return response()->json(['data' => $template]);
    }

    public function destroy(StatementTemplate $statementTemplate): JsonResponse
    {
        $this->service->delete($statementTemplate);

        return response()->json(['message' => 'Statement template deleted.']);
    }

    // ──────────────────────────────────────
    // Statement Generation
    // ──────────────────────────────────────

    public function generate(Request $request, StatementTemplate $statementTemplate): JsonResponse
    {
        $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'compare_from' => ['nullable', 'date'],
            'compare_to' => ['nullable', 'date', 'after_or_equal:compare_from'],
        ]);

        $result = $this->service->generate(
            $statementTemplate,
            $request->query('from'),
            $request->query('to'),
            $request->query('compare_from'),
            $request->query('compare_to'),
        );

        return response()->json(['data' => $result]);
    }

    // ──────────────────────────────────────
    // Financial Ratios
    // ──────────────────────────────────────

    public function ratios(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $result = $this->service->generateRatios(
            $request->query('from'),
            $request->query('to'),
        );

        return response()->json(['data' => $result]);
    }

    // ──────────────────────────────────────
    // Vertical Analysis
    // ──────────────────────────────────────

    public function verticalAnalysis(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $result = $this->service->verticalAnalysis(
            $request->query('from'),
            $request->query('to'),
        );

        return response()->json(['data' => $result]);
    }

    // ──────────────────────────────────────
    // Horizontal Analysis
    // ──────────────────────────────────────

    public function horizontalAnalysis(Request $request): JsonResponse
    {
        $request->validate([
            'from1' => ['required', 'date'],
            'to1' => ['required', 'date', 'after_or_equal:from1'],
            'from2' => ['required', 'date'],
            'to2' => ['required', 'date', 'after_or_equal:from2'],
        ]);

        $result = $this->service->horizontalAnalysis(
            $request->query('from1'),
            $request->query('to1'),
            $request->query('from2'),
            $request->query('to2'),
        );

        return response()->json(['data' => $result]);
    }
}

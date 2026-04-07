<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Accounting\Services\AccountSuggestionService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountSuggestionController extends Controller
{
    public function __construct(
        private readonly AccountSuggestionService $suggestionService,
    ) {}

    /**
     * Get account suggestions for a description.
     *
     * GET /account-suggestions?description=...
     */
    public function suggest(Request $request): JsonResponse
    {
        $request->validate([
            'description' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $suggestions = $this->suggestionService->suggest(
            $request->query('description'),
            (int) $request->query('limit', 5),
        );

        return response()->json(['data' => $suggestions]);
    }

    /**
     * Train the suggestion engine from existing journal entry history.
     *
     * POST /account-suggestions/train
     */
    public function train(): JsonResponse
    {
        $count = $this->suggestionService->trainFromHistory();

        return response()->json([
            'message' => "Learned {$count} patterns from journal entry history.",
            'patterns_learned' => $count,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Services\JournalEntryService;
use App\Http\Controllers\Controller;
use App\Http\Requests\JournalEntry\StoreJournalEntryRequest;
use App\Http\Requests\JournalEntry\UpdateJournalEntryRequest;
use App\Http\Resources\JournalEntryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class JournalEntryController extends Controller
{
    public function __construct(
        private readonly JournalEntryService $journalEntryService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $entries = $this->journalEntryService->list([
            'search' => $request->query('search'),
            'status' => $request->query('status'),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'fiscal_period_id' => $request->query('fiscal_period_id'),
            'sort_by' => $request->query('sort_by', 'date'),
            'sort_dir' => $request->query('sort_dir', 'desc'),
            'per_page' => min((int) ($request->query('per_page', 15)), 100),
        ]);

        return JournalEntryResource::collection($entries);
    }

    public function store(StoreJournalEntryRequest $request): JsonResponse
    {
        $entry = $this->journalEntryService->create($request->validated());

        return (new JournalEntryResource($entry))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(JournalEntry $journalEntry): JournalEntryResource
    {
        return new JournalEntryResource($this->journalEntryService->show($journalEntry));
    }

    public function update(UpdateJournalEntryRequest $request, JournalEntry $journalEntry): JournalEntryResource
    {
        $entry = $this->journalEntryService->update($journalEntry, $request->validated());

        return new JournalEntryResource($entry);
    }

    public function destroy(JournalEntry $journalEntry): JsonResponse
    {
        $this->journalEntryService->delete($journalEntry);

        return response()->json([
            'message' => 'Journal entry deleted successfully.',
        ]);
    }

    public function post(JournalEntry $journalEntry): JournalEntryResource
    {
        $entry = $this->journalEntryService->post($journalEntry);

        return new JournalEntryResource($entry);
    }

    public function reverse(JournalEntry $journalEntry): JsonResponse
    {
        $reversalEntry = $this->journalEntryService->reverse($journalEntry);

        return (new JournalEntryResource($reversalEntry))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Accounting\Models\RecurringJournalEntry;
use App\Domain\Accounting\Services\RecurringJournalEntryService;
use App\Http\Controllers\Controller;
use App\Http\Requests\RecurringJournalEntryRequest;
use App\Http\Resources\RecurringJournalEntryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RecurringJournalEntryController extends Controller
{
    public function __construct(
        private readonly RecurringJournalEntryService $service,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return RecurringJournalEntryResource::collection(
            $this->service->list(array_merge(
                $request->only('is_active'),
                ['per_page' => min((int) ($request->query('per_page', 15)), 100)]
            ))
        );
    }

    public function store(RecurringJournalEntryRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['tenant_id'] = app('tenant.id');
        $data['created_by'] = Auth::id();

        $recurring = $this->service->create($data);

        return (new RecurringJournalEntryResource($recurring))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(RecurringJournalEntry $recurringJournalEntry): RecurringJournalEntryResource
    {
        return new RecurringJournalEntryResource($recurringJournalEntry);
    }

    public function update(RecurringJournalEntryRequest $request, RecurringJournalEntry $recurringJournalEntry): RecurringJournalEntryResource
    {
        return new RecurringJournalEntryResource(
            $this->service->update($recurringJournalEntry, $request->validated())
        );
    }

    public function destroy(RecurringJournalEntry $recurringJournalEntry): JsonResponse
    {
        $this->service->delete($recurringJournalEntry);

        return response()->json(['message' => __('messages.success.deleted')]);
    }

    public function toggle(RecurringJournalEntry $recurringJournalEntry): RecurringJournalEntryResource
    {
        return new RecurringJournalEntryResource(
            $this->service->toggle($recurringJournalEntry)
        );
    }
}

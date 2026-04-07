<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Billing\Models\RecurringInvoice;
use App\Domain\Billing\Services\RecurringInvoiceService;
use App\Http\Controllers\Controller;
use App\Http\Resources\RecurringInvoiceResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RecurringInvoiceController extends Controller
{
    public function __construct(
        private readonly RecurringInvoiceService $service,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return RecurringInvoiceResource::collection(
            $this->service->list($request->only('is_active', 'client_id', 'per_page'))
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'frequency' => 'required|in:weekly,monthly,quarterly,yearly',
            'day_of_month' => 'nullable|integer|min:1|max:28',
            'day_of_week' => 'nullable|integer|min:0|max:6',
            'start_date' => 'required|date|after_or_equal:today',
            'end_date' => 'nullable|date|after:start_date',
            'line_items' => 'required|array|min:1',
            'line_items.*.description' => 'required|string|max:500',
            'line_items.*.quantity' => 'required|numeric|min:0.01',
            'line_items.*.unit_price' => 'required|numeric|min:0',
            'line_items.*.discount_percent' => 'nullable|numeric|min:0|max:100',
            'line_items.*.vat_rate' => 'nullable|numeric|min:0|max:100',
            'line_items.*.account_id' => 'nullable|exists:accounts,id',
            'currency' => 'nullable|string|size:3',
            'notes' => 'nullable|string|max:2000',
            'terms' => 'nullable|string|max:2000',
            'due_days' => 'nullable|integer|min:0|max:365',
            'is_active' => 'boolean',
            'auto_send' => 'boolean',
            'max_occurrences' => 'nullable|integer|min:1',
        ]);

        $data['tenant_id'] = app('tenant.id');
        $data['created_by'] = Auth::id();

        $recurring = $this->service->create($data);

        return (new RecurringInvoiceResource($recurring->load('client')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(RecurringInvoice $recurringInvoice): RecurringInvoiceResource
    {
        return new RecurringInvoiceResource($recurringInvoice->load('client'));
    }

    public function update(Request $request, RecurringInvoice $recurringInvoice): RecurringInvoiceResource
    {
        $data = $request->validate([
            'client_id' => 'nullable|exists:clients,id',
            'frequency' => 'nullable|in:weekly,monthly,quarterly,yearly',
            'day_of_month' => 'nullable|integer|min:1|max:28',
            'day_of_week' => 'nullable|integer|min:0|max:6',
            'end_date' => 'nullable|date',
            'line_items' => 'nullable|array|min:1',
            'line_items.*.description' => 'required|string|max:500',
            'line_items.*.quantity' => 'required|numeric|min:0.01',
            'line_items.*.unit_price' => 'required|numeric|min:0',
            'line_items.*.discount_percent' => 'nullable|numeric|min:0|max:100',
            'line_items.*.vat_rate' => 'nullable|numeric|min:0|max:100',
            'notes' => 'nullable|string|max:2000',
            'terms' => 'nullable|string|max:2000',
            'due_days' => 'nullable|integer|min:0|max:365',
            'is_active' => 'boolean',
            'auto_send' => 'boolean',
            'max_occurrences' => 'nullable|integer|min:1',
        ]);

        return new RecurringInvoiceResource(
            $this->service->update($recurringInvoice, $data)->load('client')
        );
    }

    public function destroy(RecurringInvoice $recurringInvoice): JsonResponse
    {
        $recurringInvoice->delete();

        return response()->json(['message' => __('messages.success.deleted')]);
    }
}

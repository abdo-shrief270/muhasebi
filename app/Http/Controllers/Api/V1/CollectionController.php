<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Collection\Services\CollectionService;
use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Collection\LogCollectionActionRequest;
use App\Http\Requests\Collection\WriteOffRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CollectionController extends Controller
{
    public function __construct(
        private readonly CollectionService $collectionService,
    ) {}

    /**
     * POST /collections/actions
     */
    public function logAction(LogCollectionActionRequest $request): JsonResponse
    {
        $action = $this->collectionService->logAction($request->validated());

        return ApiResponse::created($action);
    }

    /**
     * GET /collections/actions
     */
    public function listActions(Request $request): JsonResponse
    {
        $actions = $this->collectionService->listActions([
            'invoice_id' => $request->query('invoice_id'),
            'client_id' => $request->query('client_id'),
            'action_type' => $request->query('action_type'),
            'outcome' => $request->query('outcome'),
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'per_page' => min((int) ($request->query('per_page', 15)), 100),
        ]);

        return ApiResponse::success($actions);
    }

    /**
     * GET /collections/overview
     */
    public function overview(): JsonResponse
    {
        $data = $this->collectionService->overview();

        return ApiResponse::success($data);
    }

    /**
     * POST /invoices/{invoice}/write-off
     */
    public function writeOff(WriteOffRequest $request, Invoice $invoice): JsonResponse
    {
        $result = $this->collectionService->writeOff($invoice, $request->validated());

        return ApiResponse::success($result);
    }

    /**
     * POST /invoices/{invoice}/escalate
     */
    public function escalate(Invoice $invoice): JsonResponse
    {
        $result = $this->collectionService->escalate($invoice);

        return ApiResponse::success($result);
    }

    /**
     * GET /collections/clients/{client}
     */
    public function clientSummary(int $client): JsonResponse
    {
        $data = $this->collectionService->clientCollectionSummary($client);

        return ApiResponse::success($data);
    }

    /**
     * GET /collections/reports/effectiveness
     */
    public function effectiveness(Request $request): JsonResponse
    {
        $data = $this->collectionService->effectivenessReport([
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
        ]);

        return ApiResponse::success($data);
    }
}

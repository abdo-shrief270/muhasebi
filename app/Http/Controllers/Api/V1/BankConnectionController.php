<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Banking\Models\BankConnection;
use App\Domain\Banking\Services\BankConnectionService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Banking\StoreBankConnectionRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BankConnectionController extends Controller
{
    public function __construct(
        private readonly BankConnectionService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $this->service->list([
            'bank_code' => $request->query('bank_code'),
            'is_active' => $request->query('is_active'),
            'per_page' => min((int) ($request->query('per_page', 15)), 100),
        ]);

        return response()->json($data);
    }

    public function store(StoreBankConnectionRequest $request): JsonResponse
    {
        $connection = $this->service->create($request->validated());

        return response()->json([
            'data' => $connection->load('glAccount:id,code,name_ar,name_en'),
            'message' => 'Bank connection created.',
        ], Response::HTTP_CREATED);
    }

    public function show(BankConnection $bankConnection): JsonResponse
    {
        return response()->json([
            'data' => $bankConnection->load('glAccount:id,code,name_ar,name_en', 'creator:id,name'),
        ]);
    }

    public function update(StoreBankConnectionRequest $request, BankConnection $bankConnection): JsonResponse
    {
        $connection = $this->service->update($bankConnection, $request->validated());

        return response()->json([
            'data' => $connection->load('glAccount:id,code,name_ar,name_en'),
            'message' => 'Bank connection updated.',
        ]);
    }

    public function destroy(BankConnection $bankConnection): JsonResponse
    {
        $this->service->delete($bankConnection);

        return response()->json(['message' => 'Bank connection deleted.']);
    }

    public function syncBalance(BankConnection $bankConnection): JsonResponse
    {
        $result = $this->service->syncBalance($bankConnection);

        return response()->json([
            'data' => $result,
            'message' => $result['synced'] ? 'Balance synced from bank.' : 'API sync not available. Showing stored balance.',
        ]);
    }

    public function importStatement(Request $request, BankConnection $bankConnection): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:10240'], // 10MB max
            'format' => ['required', 'string', 'in:csv,ofx,mt940'],
        ]);

        if (! $bankConnection->linked_gl_account_id) {
            return response()->json([
                'message' => 'Bank connection must be linked to a GL account before importing statements.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $result = $this->service->importStatement(
            $bankConnection,
            $request->file('file'),
            $request->input('format'),
        );

        return response()->json([
            'data' => $result,
            'message' => "Imported {$result['lines_imported']} statement lines.",
        ]);
    }

    public function generateInstruction(Request $request): JsonResponse
    {
        $data = $request->validate([
            'format' => ['nullable', 'string', 'in:mt103,local'],
            'reference' => ['nullable', 'string', 'max:50'],
            'date' => ['nullable', 'date'],
            'currency' => ['nullable', 'string', 'size:3'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'sender_name' => ['required', 'string', 'max:255'],
            'sender_account' => ['required', 'string', 'max:50'],
            'receiver_name' => ['required', 'string', 'max:255'],
            'receiver_account' => ['required', 'string', 'max:50'],
            'receiver_bank_code' => ['nullable', 'string', 'max:20'],
            'details' => ['nullable', 'string', 'max:500'],
        ]);

        $result = $this->service->generatePaymentInstruction($data);

        return response()->json([
            'data' => $result,
            'message' => 'Payment instruction generated.',
        ]);
    }

    public function supportedFormats(Request $request): JsonResponse
    {
        $bankCode = $request->query('bank_code', 'other');

        return response()->json([
            'data' => $this->service->listSupportedFormats((string) $bankCode),
        ]);
    }

    public function dashboard(): JsonResponse
    {
        return response()->json($this->service->dashboard());
    }
}

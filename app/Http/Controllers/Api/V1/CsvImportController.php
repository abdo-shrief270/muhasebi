<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Shared\Services\CsvImportService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CsvImportController extends Controller
{
    public function __construct(private readonly CsvImportService $service) {}

    public function importClients(Request $request): JsonResponse
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt|max:5120']);
        $result = $this->service->importClients($request->file('file'), $request->user()->tenant_id);

        return response()->json(['data' => $result]);
    }

    public function importAccounts(Request $request): JsonResponse
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt|max:5120']);
        $result = $this->service->importAccounts($request->file('file'), $request->user()->tenant_id);

        return response()->json(['data' => $result]);
    }

    public function importOpeningBalances(Request $request): JsonResponse
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt|max:5120']);
        $result = $this->service->importOpeningBalances($request->file('file'), $request->user()->tenant_id);

        return response()->json(['data' => $result]);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ImportCsvJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CsvImportController extends Controller
{
    public function importClients(Request $request): JsonResponse
    {
        return $this->dispatchImport($request, 'clients');
    }

    public function importAccounts(Request $request): JsonResponse
    {
        return $this->dispatchImport($request, 'accounts');
    }

    public function importOpeningBalances(Request $request): JsonResponse
    {
        return $this->dispatchImport($request, 'opening_balances');
    }

    private function dispatchImport(Request $request, string $importType): JsonResponse
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt|max:5120']);

        $path = $request->file('file')->store('csv-imports');

        ImportCsvJob::dispatch(
            storage_path('app/'.$path),
            $importType,
            (int) app('tenant.id'),
        );

        return response()->json([
            'message' => 'Import queued for processing.',
            'import_type' => $importType,
        ], 202);
    }
}

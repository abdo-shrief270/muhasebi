<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Import\Models\ImportJob;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessImportJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\File;

class ImportController extends Controller
{
    /**
     * Upload a CSV file and start an import job.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', File::types(['csv', 'txt'])->max(10 * 1024)], // 10MB max
            'type' => 'required|in:clients,accounts,opening_balances',
            'options' => 'nullable|array',
            'options.delimiter' => 'nullable|string|size:1',
            'options.skip_header' => 'nullable|boolean',
            'options.encoding' => 'nullable|string|in:UTF-8,Windows-1256,ISO-8859-6',
        ]);

        $path = $request->file('file')->store('imports', 'local');

        $job = ImportJob::create([
            'tenant_id' => app('tenant.id'),
            'user_id' => Auth::id(),
            'type' => $request->input('type'),
            'file_path' => $path,
            'original_filename' => $request->file('file')->getClientOriginalName(),
            'status' => 'pending',
            'options' => $request->input('options', []),
        ]);

        ProcessImportJob::dispatch($job->id);

        return response()->json([
            'data' => [
                'id' => $job->id,
                'status' => 'pending',
                'message' => __('messages.success.sent'),
            ],
        ], 201);
    }

    /**
     * Get import job status.
     */
    public function show(ImportJob $importJob): JsonResponse
    {
        return response()->json([
            'data' => [
                'id' => $importJob->id,
                'type' => $importJob->type,
                'original_filename' => $importJob->original_filename,
                'status' => $importJob->status,
                'total_rows' => $importJob->total_rows,
                'processed_rows' => $importJob->processed_rows,
                'success_count' => $importJob->success_count,
                'error_count' => $importJob->error_count,
                'errors' => $importJob->errors,
                'started_at' => $importJob->started_at?->toISOString(),
                'completed_at' => $importJob->completed_at?->toISOString(),
            ],
        ]);
    }

    /**
     * List import history.
     */
    public function index(Request $request): JsonResponse
    {
        $jobs = ImportJob::latest()
            ->paginate($request->input('per_page', 15));

        return response()->json($jobs);
    }

    /**
     * Download a sample CSV template for a given import type.
     */
    public function template(string $type): JsonResponse
    {
        $templates = [
            'clients' => [
                'headers' => ['name', 'email', 'phone', 'tax_id', 'address', 'city'],
                'sample' => ['شركة الأمل,amal@example.com,+201234567890,123456789,15 شارع التحرير,القاهرة'],
            ],
            'accounts' => [
                'headers' => ['code', 'name_ar', 'name_en', 'type', 'parent_code'],
                'sample' => ['1100,الأصول المتداولة,Current Assets,asset,1000'],
            ],
            'opening_balances' => [
                'headers' => ['account_code', 'debit', 'credit'],
                'sample' => ['1100,50000,0'],
            ],
        ];

        if (! isset($templates[$type])) {
            return response()->json(['message' => 'Unknown template type.'], 404);
        }

        return response()->json(['data' => $templates[$type]]);
    }
}

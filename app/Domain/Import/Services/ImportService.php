<?php

declare(strict_types=1);

namespace App\Domain\Import\Services;

use App\Domain\Accounting\Models\Account;
use App\Domain\Client\Models\Client;
use App\Domain\Import\Models\ImportJob;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Handles CSV data imports with validation and error reporting.
 * Supports: clients, accounts (chart of accounts), opening_balances.
 */
class ImportService
{
    /**
     * Process an import job.
     */
    public function process(ImportJob $job): void
    {
        $job->markProcessing();

        try {
            $rows = $this->parseCsv($job->file_path, $job->options ?? []);
            $job->update(['total_rows' => count($rows)]);

            match ($job->type) {
                'clients' => $this->importClients($job, $rows),
                'accounts' => $this->importAccounts($job, $rows),
                'opening_balances' => $this->importOpeningBalances($job, $rows),
                default => throw new \InvalidArgumentException("Unknown import type: {$job->type}"),
            };

            $job->markCompleted();
        } catch (\Throwable $e) {
            $job->markFailed($e->getMessage());
            Log::error("Import job #{$job->id} failed", ['error' => $e->getMessage()]);
        }
    }

    /**
     * Parse a CSV file into an array of associative arrays.
     */
    private function parseCsv(string $path, array $options = []): array
    {
        $fullPath = storage_path('app/'.$path);
        if (! file_exists($fullPath)) {
            throw new \RuntimeException("Import file not found: {$path}");
        }

        $delimiter = $options['delimiter'] ?? ',';
        $encoding = $options['encoding'] ?? 'UTF-8';
        $skipHeader = $options['skip_header'] ?? true;

        $content = file_get_contents($fullPath);

        // Handle BOM
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        // Convert encoding if needed
        if ($encoding !== 'UTF-8') {
            $content = mb_convert_encoding($content, 'UTF-8', $encoding);
        }

        $lines = str_getcsv_all($content, $delimiter);

        if (empty($lines)) {
            return [];
        }

        $headers = array_map('trim', array_map('strtolower', $lines[0]));
        $rows = [];

        $startIndex = $skipHeader ? 1 : 0;
        for ($i = $startIndex; $i < count($lines); $i++) {
            if (count($lines[$i]) === count($headers)) {
                $rows[] = array_combine($headers, array_map('trim', $lines[$i]));
            }
        }

        return $rows;
    }

    /**
     * Import clients from CSV.
     * Expected columns: name, email, phone, tax_id, address, city
     */
    private function importClients(ImportJob $job, array $rows): void
    {
        foreach ($rows as $index => $row) {
            $rowNum = $index + 2; // +2 for header + 0-index

            $validator = Validator::make($row, [
                'name' => 'required|string|max:255',
                'email' => 'nullable|email|max:255',
                'phone' => 'nullable|string|max:20',
                'tax_id' => 'nullable|string|max:20',
                'address' => 'nullable|string|max:500',
                'city' => 'nullable|string|max:100',
            ]);

            if ($validator->fails()) {
                foreach ($validator->errors()->all() as $error) {
                    $job->addError($rowNum, '', $error);
                }

                continue;
            }

            try {
                Client::updateOrCreate(
                    ['tenant_id' => $job->tenant_id, 'name' => $row['name']],
                    array_filter([
                        'tenant_id' => $job->tenant_id,
                        'name' => $row['name'],
                        'email' => $row['email'] ?? null,
                        'phone' => $row['phone'] ?? null,
                        'tax_id' => $row['tax_id'] ?? null,
                        'address' => $row['address'] ?? null,
                        'city' => $row['city'] ?? null,
                    ]),
                );
                $job->incrementProgress();
            } catch (\Throwable $e) {
                $job->addError($rowNum, 'name', "Failed to import: {$e->getMessage()}");
            }
        }
    }

    /**
     * Import chart of accounts from CSV.
     * Expected columns: code, name_ar, name_en, type, parent_code
     */
    private function importAccounts(ImportJob $job, array $rows): void
    {
        // First pass: create all accounts
        foreach ($rows as $index => $row) {
            $rowNum = $index + 2;

            $validator = Validator::make($row, [
                'code' => 'required|string|max:20',
                'name_ar' => 'required|string|max:255',
                'name_en' => 'nullable|string|max:255',
                'type' => 'required|string|in:asset,liability,equity,revenue,expense',
            ]);

            if ($validator->fails()) {
                foreach ($validator->errors()->all() as $error) {
                    $job->addError($rowNum, '', $error);
                }

                continue;
            }

            try {
                $parentCode = $row['parent_code'] ?? null;
                $parentId = null;

                if ($parentCode) {
                    $parent = Account::where('tenant_id', $job->tenant_id)
                        ->where('code', $parentCode)->first();
                    $parentId = $parent?->id;
                }

                if ($parentId) {
                    $ancestors = [];
                    $checkId = $parentId;
                    while ($checkId) {
                        if (in_array($checkId, $ancestors)) {
                            $parentId = null;
                            break;
                        }
                        $ancestors[] = $checkId;
                        $checkId = Account::where('id', $checkId)->value('parent_id');
                    }
                }

                Account::updateOrCreate(
                    ['tenant_id' => $job->tenant_id, 'code' => $row['code']],
                    [
                        'tenant_id' => $job->tenant_id,
                        'code' => $row['code'],
                        'name_ar' => $row['name_ar'],
                        'name_en' => $row['name_en'] ?? $row['name_ar'],
                        'type' => $row['type'],
                        'parent_id' => $parentId,
                    ],
                );
                $job->incrementProgress();
            } catch (\Throwable $e) {
                $job->addError($rowNum, 'code', "Failed: {$e->getMessage()}");
            }
        }
    }

    /**
     * Import opening balances from CSV.
     * Expected columns: account_code, debit, credit
     */
    private function importOpeningBalances(ImportJob $job, array $rows): void
    {
        DB::transaction(function () use ($job, $rows) {
            $entries = [];

            foreach ($rows as $index => $row) {
                $rowNum = $index + 2;

                $validator = Validator::make($row, [
                    'account_code' => 'required|string',
                    'debit' => 'nullable|numeric|min:0',
                    'credit' => 'nullable|numeric|min:0',
                ]);

                if ($validator->fails()) {
                    foreach ($validator->errors()->all() as $error) {
                        $job->addError($rowNum, '', $error);
                    }

                    continue;
                }

                $account = Account::where('tenant_id', $job->tenant_id)
                    ->where('code', $row['account_code'])->first();

                if (! $account) {
                    $job->addError($rowNum, 'account_code', "Account not found: {$row['account_code']}");

                    continue;
                }

                $debit = Money::of($row['debit'] ?? 0);
                $credit = Money::of($row['credit'] ?? 0);

                if (Money::isZero($debit) && Money::isZero($credit)) {
                    continue;
                }

                $entries[] = [
                    'account_id' => $account->id,
                    'debit' => $debit,
                    'credit' => $credit,
                ];

                $job->incrementProgress();
            }

            // Validate total debits = total credits using precise bcmath sums; a
            // float-based check would drift across hundreds of CSV rows.
            $totalDebit = Money::sum(array_column($entries, 'debit'));
            $totalCredit = Money::sum(array_column($entries, 'credit'));

            if (! Money::isZero(Money::sub($totalDebit, $totalCredit))) {
                $job->addError(0, '', "Opening balances don't balance: Debit={$totalDebit}, Credit={$totalCredit}");
            }
        });
    }
}

/**
 * Parse CSV string into array of rows.
 */
function str_getcsv_all(string $content, string $delimiter = ','): array
{
    $rows = [];
    $lines = explode("\n", $content);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line !== '') {
            $rows[] = str_getcsv($line, $delimiter);
        }
    }

    return $rows;
}

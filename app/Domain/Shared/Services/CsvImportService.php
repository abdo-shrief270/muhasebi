<?php

declare(strict_types=1);

namespace App\Domain\Shared\Services;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\JournalEntryStatus;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalEntryLine;
use App\Domain\Client\Models\Client;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class CsvImportService
{
    /**
     * Import clients from a CSV file.
     *
     * Expected columns: name, email, phone, tax_number, address
     *
     * @return array{imported: int, skipped: int, errors: list<string>}
     */
    public function importClients(UploadedFile $file, int $tenantId): array
    {
        $rows = $this->parseCsv($file);
        $imported = 0;
        $skipped = 0;
        $errors = [];

        $existingEmails = Client::where('tenant_id', $tenantId)
            ->whereNotNull('email')
            ->pluck('email')
            ->map(fn (string $e) => strtolower($e))
            ->flip()
            ->all();

        DB::transaction(function () use ($rows, $tenantId, &$imported, &$skipped, &$errors, $existingEmails): void {
            foreach ($rows as $index => $row) {
                $rowNumber = $index + 2; // +2 because index 0 = row 2 (after header)

                $name = trim($row['name'] ?? '');
                $email = trim($row['email'] ?? '');
                $phone = trim($row['phone'] ?? '');
                $taxNumber = trim($row['tax_number'] ?? '');
                $address = trim($row['address'] ?? '');

                if ($name === '') {
                    $errors[] = "Row {$rowNumber}: name is required.";

                    continue;
                }

                if ($email !== '' && isset($existingEmails[strtolower($email)])) {
                    $skipped++;

                    continue;
                }

                Client::create([
                    'tenant_id' => $tenantId,
                    'name' => $name,
                    'email' => $email !== '' ? $email : null,
                    'phone' => $phone !== '' ? $phone : null,
                    'tax_id' => $taxNumber !== '' ? $taxNumber : null,
                    'address' => $address !== '' ? $address : null,
                ]);

                if ($email !== '') {
                    $existingEmails[strtolower($email)] = true;
                }

                $imported++;
            }
        });

        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * Import chart of accounts from a CSV file.
     *
     * Expected columns: code, name, type, parent_code
     *
     * @return array{imported: int, skipped: int, errors: list<string>}
     */
    public function importAccounts(UploadedFile $file, int $tenantId): array
    {
        $rows = $this->parseCsv($file);
        $imported = 0;
        $skipped = 0;
        $errors = [];

        $validTypes = array_map(
            fn (AccountType $t) => $t->value,
            AccountType::cases(),
        );

        DB::transaction(function () use ($rows, $tenantId, &$imported, &$skipped, &$errors, $validTypes): void {
            // Pre-load existing account codes for this tenant
            $existingCodes = Account::where('tenant_id', $tenantId)
                ->pluck('id', 'code')
                ->all();

            foreach ($rows as $index => $row) {
                $rowNumber = $index + 2;

                $code = trim($row['code'] ?? '');
                $name = trim($row['name'] ?? '');
                $type = strtolower(trim($row['type'] ?? ''));
                $parentCode = trim($row['parent_code'] ?? '');

                if ($code === '' || $name === '') {
                    $errors[] = "Row {$rowNumber}: code and name are required.";

                    continue;
                }

                if (! in_array($type, $validTypes, true)) {
                    $errors[] = "Row {$rowNumber}: invalid account type '{$type}'. Must be one of: ".implode(', ', $validTypes).'.';

                    continue;
                }

                if (isset($existingCodes[$code])) {
                    $skipped++;

                    continue;
                }

                $parentId = null;
                if ($parentCode !== '') {
                    $parentId = $existingCodes[$parentCode] ?? null;
                    if ($parentId === null) {
                        $errors[] = "Row {$rowNumber}: parent_code '{$parentCode}' not found.";

                        continue;
                    }
                }

                $accountType = AccountType::from($type);

                $account = Account::create([
                    'tenant_id' => $tenantId,
                    'code' => $code,
                    'name_ar' => $name,
                    'name_en' => $name,
                    'type' => $accountType,
                    'normal_balance' => $accountType->normalBalance(),
                    'parent_id' => $parentId,
                ]);

                $existingCodes[$code] = $account->id;
                $imported++;
            }
        });

        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * Import opening balances as a single journal entry from a CSV file.
     *
     * Expected columns: account_code, debit, credit
     *
     * @return array{imported: int, skipped: int, errors: list<string>}
     */
    public function importOpeningBalances(UploadedFile $file, int $tenantId): array
    {
        $rows = $this->parseCsv($file);
        $imported = 0;
        $skipped = 0;
        $errors = [];

        // Pre-load account codes for this tenant
        $accountMap = Account::where('tenant_id', $tenantId)
            ->pluck('id', 'code')
            ->all();

        $validLines = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;

            $accountCode = trim($row['account_code'] ?? '');
            $debit = trim($row['debit'] ?? '0');
            $credit = trim($row['credit'] ?? '0');

            if ($accountCode === '') {
                $errors[] = "Row {$rowNumber}: account_code is required.";

                continue;
            }

            $accountId = $accountMap[$accountCode] ?? null;
            if ($accountId === null) {
                $errors[] = "Row {$rowNumber}: account_code '{$accountCode}' not found.";

                continue;
            }

            $debitAmount = is_numeric($debit) ? round((float) $debit, 2) : 0.00;
            $creditAmount = is_numeric($credit) ? round((float) $credit, 2) : 0.00;

            if ($debitAmount == 0 && $creditAmount == 0) {
                $skipped++;

                continue;
            }

            if ($debitAmount > 0 && $creditAmount > 0) {
                $errors[] = "Row {$rowNumber}: a line cannot have both debit and credit.";

                continue;
            }

            $validLines[] = [
                'account_id' => $accountId,
                'debit' => $debitAmount,
                'credit' => $creditAmount,
            ];
        }

        if (empty($validLines)) {
            return ['imported' => 0, 'skipped' => $skipped, 'errors' => $errors];
        }

        // Verify total debits equal total credits
        $totalDebit = array_sum(array_column($validLines, 'debit'));
        $totalCredit = array_sum(array_column($validLines, 'credit'));

        if (bccomp((string) $totalDebit, (string) $totalCredit, 2) !== 0) {
            $errors[] = "Total debits ({$totalDebit}) do not equal total credits ({$totalCredit}). Journal entry must be balanced.";

            return ['imported' => 0, 'skipped' => $skipped, 'errors' => $errors];
        }

        DB::transaction(function () use ($validLines, $tenantId, $totalDebit, $totalCredit, &$imported): void {
            $entryNumber = 'OB-'.now()->format('YmdHis');

            $journalEntry = JournalEntry::create([
                'tenant_id' => $tenantId,
                'entry_number' => $entryNumber,
                'date' => now()->toDateString(),
                'description' => 'Opening Balances Import',
                'status' => JournalEntryStatus::Draft,
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
            ]);

            foreach ($validLines as $line) {
                JournalEntryLine::create([
                    'journal_entry_id' => $journalEntry->id,
                    'account_id' => $line['account_id'],
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                    'description' => 'Opening balance',
                ]);

                $imported++;
            }
        });

        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * Parse a CSV file into an array of associative arrays.
     *
     * Handles UTF-8 BOM and normalizes header names.
     *
     * @return list<array<string, string>>
     */
    private function parseCsv(UploadedFile $file): array
    {
        $content = file_get_contents($file->getRealPath());

        // Strip UTF-8 BOM if present
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        $lines = preg_split('/\r\n|\r|\n/', $content);
        $lines = array_filter($lines, fn (string $line) => trim($line) !== '');

        if (count($lines) < 2) {
            return [];
        }

        $headerLine = array_shift($lines);
        $headers = str_getcsv($headerLine);
        $headers = array_map(fn (string $h) => strtolower(trim($h)), $headers);

        $rows = [];
        foreach ($lines as $line) {
            $values = str_getcsv($line);

            // Skip lines that don't match header count
            if (count($values) !== count($headers)) {
                continue;
            }

            $rows[] = array_combine($headers, $values);
        }

        return $rows;
    }
}

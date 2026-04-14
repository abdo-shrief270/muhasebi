<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use Illuminate\Validation\ValidationException;

class GLPostingService
{
    public function __construct(
        private readonly JournalEntryService $journalEntryService,
    ) {}

    /**
     * Post a journal entry to the General Ledger.
     *
     * @param array{date: string, description: string, description_ar?: string, reference?: string, lines: array<int, array{account_id: int, debit: float|string, credit: float|string, currency?: string, description?: string}>} $data
     */
    public function post(array $data): JournalEntry
    {
        $journalEntry = $this->journalEntryService->create($data);
        $this->journalEntryService->post($journalEntry);

        return $journalEntry;
    }

    /**
     * Reverse an existing journal entry.
     */
    public function reverse(JournalEntry $entry, ?string $date = null): JournalEntry
    {
        return $this->journalEntryService->reverse($entry, $date);
    }

    /**
     * Resolve a GL account ID by its code for a given tenant.
     */
    public function resolveAccount(string $code, int $tenantId): int
    {
        $account = Account::query()
            ->forTenant($tenantId)
            ->where('code', $code)
            ->first();

        if (! $account) {
            throw ValidationException::withMessages([
                'account' => ["Required account with code '{$code}' not found. Please set up your chart of accounts."],
            ]);
        }

        return $account->id;
    }

    /**
     * Resolve a GL account ID from a config key with tenant override.
     */
    public function resolveFromConfig(string $configKey, int $tenantId, ?int $override = null): int
    {
        if ($override !== null) {
            return $override;
        }

        $code = config("accounting.default_accounts.{$configKey}");

        if (! $code) {
            throw ValidationException::withMessages([
                'account' => ["Account config key '{$configKey}' is not configured."],
            ]);
        }

        return $this->resolveAccount($code, $tenantId);
    }
}

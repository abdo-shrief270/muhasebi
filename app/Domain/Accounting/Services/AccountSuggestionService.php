<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Enums\JournalEntryStatus;
use App\Domain\Accounting\Models\AccountSuggestion;
use Illuminate\Support\Facades\DB;

/**
 * AI-powered transaction categorization.
 * Learns from past journal entries to suggest account codes for new descriptions.
 */
class AccountSuggestionService
{
    /**
     * Suggest accounts for a transaction description.
     *
     * @return array<int, array{account_id: int, code: string, name_ar: string, name_en: string, confidence: int}>
     */
    public function suggest(string $description, int $limit = 5): array
    {
        $normalized = $this->normalize($description);
        $words = array_filter(explode(' ', $normalized), fn ($w) => mb_strlen($w) >= 3);

        if (empty($words)) {
            return [];
        }

        $tenantId = (int) app('tenant.id');

        // Exact pattern match first
        $exact = AccountSuggestion::query()
            ->where('tenant_id', $tenantId)
            ->with('account:id,code,name_ar,name_en')
            ->where('pattern', $normalized)
            ->orderByDesc('confidence')
            ->limit($limit)
            ->get();

        if ($exact->isNotEmpty()) {
            return $exact->map(fn ($s) => [
                'account_id' => $s->account_id,
                'code' => $s->account?->code,
                'name_ar' => $s->account?->name_ar,
                'name_en' => $s->account?->name_en,
                'confidence' => $s->confidence,
                'match_type' => 'exact',
            ])->toArray();
        }

        // Fuzzy match: find patterns containing any of the key words
        $query = AccountSuggestion::query()
            ->where('tenant_id', $tenantId)
            ->with('account:id,code,name_ar,name_en');

        $query->where(function ($q) use ($words) {
            foreach ($words as $word) {
                $q->orWhere('pattern', 'ilike', "%{$word}%");
            }
        });

        return $query
            ->orderByDesc('confidence')
            ->limit($limit)
            ->get()
            ->unique('account_id')
            ->map(fn ($s) => [
                'account_id' => $s->account_id,
                'code' => $s->account?->code,
                'name_ar' => $s->account?->name_ar,
                'name_en' => $s->account?->name_en,
                'confidence' => $s->confidence,
                'match_type' => 'fuzzy',
            ])
            ->values()
            ->toArray();
    }

    /**
     * Learn from a confirmed categorization.
     * Called when a journal entry is created/posted with a description + account.
     */
    public function learn(string $description, int $accountId): void
    {
        $normalized = $this->normalize($description);

        if (mb_strlen($normalized) < 3) {
            return;
        }

        AccountSuggestion::updateOrCreate(
            [
                'tenant_id' => (int) app('tenant.id'),
                'pattern' => $normalized,
                'account_id' => $accountId,
            ],
            [],
        );

        // Increment confidence
        AccountSuggestion::where('tenant_id', (int) app('tenant.id'))
            ->where('pattern', $normalized)
            ->where('account_id', $accountId)
            ->increment('confidence');
    }

    /**
     * Bulk learn from existing journal entries (initial training).
     *
     * @return int Number of patterns learned
     */
    public function trainFromHistory(): int
    {
        $tenantId = (int) app('tenant.id');
        $learned = 0;

        $entries = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.tenant_id', $tenantId)
            ->where('journal_entries.status', JournalEntryStatus::Posted->value)
            ->whereNull('journal_entries.deleted_at')
            ->whereNotNull('journal_entry_lines.description')
            ->where('journal_entry_lines.description', '!=', '')
            ->select('journal_entry_lines.description', 'journal_entry_lines.account_id')
            ->distinct()
            ->get();

        foreach ($entries as $entry) {
            $this->learn($entry->description, $entry->account_id);
            $learned++;
        }

        return $learned;
    }

    /**
     * Normalize description for pattern matching.
     */
    private function normalize(string $description): string
    {
        $text = mb_strtolower(trim($description));
        $text = preg_replace('/\s+/', ' ', $text);
        // Remove invoice/entry numbers to generalize patterns
        $text = preg_replace('/\b(inv|je|cn|dn|فاتورة رقم)\s*[-#]?\s*\d+\b/u', '', $text);
        $text = preg_replace('/\b\d{4,}\b/', '', $text); // Remove long numbers

        return trim(preg_replace('/\s+/', ' ', $text));
    }
}

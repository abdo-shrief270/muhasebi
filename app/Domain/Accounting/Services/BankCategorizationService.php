<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\BankCategorizationRule;
use App\Domain\Accounting\Models\BankReconciliation;
use App\Domain\Accounting\Models\BankStatementLine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class BankCategorizationService
{
    /**
     * Auto-categorize unmatched statement lines for a reconciliation.
     *
     * 1. Try rule-based matching first (ordered by priority desc)
     * 2. Fuzzy matching against past categorized lines
     * 3. Set suggested_account_id, confidence_score, is_auto_categorized
     *
     * @return int Number of categorized lines
     */
    public function categorize(BankReconciliation $recon): int
    {
        $tenantId = (int) app('tenant.id');
        $categorized = 0;

        $rules = BankCategorizationRule::where('tenant_id', $tenantId)
            ->active()
            ->orderByDesc('priority')
            ->get();

        $recon->statementLines()
            ->unmatched()
            ->where('is_auto_categorized', false)
            ->whereNull('suggested_account_id')
            ->chunk(200, function ($lines) use ($rules, $tenantId, &$categorized) {
                foreach ($lines as $line) {
                    if ($this->tryRuleMatch($line, $rules)) {
                        $categorized++;

                        continue;
                    }

                    if ($this->tryFuzzyMatch($line, $tenantId)) {
                        $categorized++;
                    }
                }
            });

        return $categorized;
    }

    /**
     * Try to match a statement line against categorization rules.
     *
     * @param  Collection<int, BankCategorizationRule>  $rules
     */
    private function tryRuleMatch(BankStatementLine $line, $rules): bool
    {
        if (empty($line->description)) {
            return false;
        }

        foreach ($rules as $rule) {
            if ($rule->matches($line->description)) {
                $confidence = match ($rule->match_type) {
                    'exact' => 100.00,
                    'contains' => 80.00,
                    'starts_with' => 85.00,
                    'regex' => 75.00,
                    default => 70.00,
                };

                $line->update([
                    'suggested_account_id' => $rule->account_id,
                    'suggested_vendor_id' => $rule->vendor_id,
                    'confidence_score' => $confidence,
                    'category_rule_id' => $rule->id,
                    'is_auto_categorized' => true,
                ]);

                $rule->increment('use_count');

                return true;
            }
        }

        return false;
    }

    /**
     * Try fuzzy matching based on description against past categorized lines.
     */
    private function tryFuzzyMatch(BankStatementLine $line, int $tenantId): bool
    {
        if (empty($line->description)) {
            return false;
        }

        $normalized = mb_strtolower(trim($line->description));
        $words = array_filter(explode(' ', $normalized), fn ($w) => mb_strlen($w) >= 3);

        if (empty($words)) {
            return false;
        }

        // Look for past statement lines that were categorized (have suggested_account_id and were confirmed)
        $query = BankStatementLine::query()
            ->join('bank_reconciliations', 'bank_statement_lines.reconciliation_id', '=', 'bank_reconciliations.id')
            ->where('bank_reconciliations.tenant_id', $tenantId)
            ->where('bank_statement_lines.id', '!=', $line->id)
            ->whereNotNull('bank_statement_lines.suggested_account_id')
            ->where('bank_statement_lines.is_auto_categorized', true);

        $query->where(function ($q) use ($words) {
            foreach ($words as $word) {
                $q->orWhere('bank_statement_lines.description', 'ilike', "%{$word}%");
            }
        });

        $match = $query
            ->select('bank_statement_lines.suggested_account_id', 'bank_statement_lines.suggested_vendor_id')
            ->orderByDesc('bank_statement_lines.confidence_score')
            ->first();

        if ($match) {
            $line->update([
                'suggested_account_id' => $match->suggested_account_id,
                'suggested_vendor_id' => $match->suggested_vendor_id,
                'confidence_score' => 60.00,
                'category_rule_id' => null,
                'is_auto_categorized' => true,
            ]);

            return true;
        }

        return false;
    }

    /**
     * Create a new categorization rule.
     *
     * @param  array<string, mixed>  $data
     */
    public function createRule(array $data): BankCategorizationRule
    {
        return BankCategorizationRule::create([
            'tenant_id' => (int) app('tenant.id'),
            'pattern' => $data['pattern'],
            'match_type' => $data['match_type'],
            'account_id' => $data['account_id'],
            'vendor_id' => $data['vendor_id'] ?? null,
            'priority' => $data['priority'] ?? 0,
            'is_active' => $data['is_active'] ?? true,
            'created_by' => auth()->id(),
        ]);
    }

    /**
     * List categorization rules for the current tenant.
     *
     * @param  array<string, mixed>  $filters
     */
    public function listRules(array $filters = []): LengthAwarePaginator
    {
        return BankCategorizationRule::query()
            ->where('tenant_id', (int) app('tenant.id'))
            ->with(['account:id,code,name_ar,name_en'])
            ->when(isset($filters['is_active']), fn ($q) => $q->where('is_active', (bool) $filters['is_active']))
            ->when(isset($filters['match_type']), fn ($q) => $q->where('match_type', $filters['match_type']))
            ->orderByDesc('priority')
            ->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Delete a categorization rule.
     */
    public function deleteRule(int $ruleId): void
    {
        BankCategorizationRule::where('tenant_id', (int) app('tenant.id'))
            ->findOrFail($ruleId)
            ->delete();
    }

    /**
     * Learn from a manual categorization — create or update a rule for that pattern.
     */
    public function learnFromMatch(BankStatementLine $line, int $accountId, ?int $vendorId = null): void
    {
        if (empty($line->description)) {
            return;
        }

        $tenantId = (int) app('tenant.id');
        $pattern = mb_strtolower(trim($line->description));

        // Remove long numbers to generalize
        $pattern = preg_replace('/\b\d{4,}\b/', '', $pattern);
        $pattern = trim(preg_replace('/\s+/', ' ', $pattern));

        if (mb_strlen($pattern) < 3) {
            return;
        }

        $rule = BankCategorizationRule::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'pattern' => $pattern,
                'match_type' => 'contains',
            ],
            [
                'account_id' => $accountId,
                'vendor_id' => $vendorId,
                'created_by' => auth()->id(),
            ],
        );

        $rule->increment('use_count');
    }

    /**
     * Accept an auto-suggestion — set account_id from suggested_account_id.
     */
    public function applySuggestion(BankStatementLine $line): BankStatementLine
    {
        if (! $line->suggested_account_id) {
            throw new \InvalidArgumentException('No suggestion available for this line.');
        }

        $line->update([
            'journal_entry_line_id' => $line->journal_entry_line_id, // preserve existing
            'status' => $line->status, // preserve
        ]);

        // If the line has a category rule, increment its use_count
        if ($line->category_rule_id) {
            BankCategorizationRule::where('id', $line->category_rule_id)
                ->increment('use_count');
        }

        return $line->refresh();
    }
}

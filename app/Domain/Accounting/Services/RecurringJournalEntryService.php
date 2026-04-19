<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\RecurringJournalEntry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RecurringJournalEntryService
{
    public function __construct(
        private readonly JournalEntryService $journalEntryService,
    ) {}

    /**
     * List recurring journal entries with optional filters.
     *
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = RecurringJournalEntry::query()
            ->latest('created_at');

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active'] === 'true' || $filters['is_active'] === true);
        }

        return $query->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Create a new recurring journal entry template.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): RecurringJournalEntry
    {
        return RecurringJournalEntry::create($data);
    }

    /**
     * Update an existing recurring journal entry template.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(RecurringJournalEntry $recurringJournalEntry, array $data): RecurringJournalEntry
    {
        $recurringJournalEntry->update($data);

        return $recurringJournalEntry->fresh();
    }

    /**
     * Soft-delete a recurring journal entry template.
     */
    public function delete(RecurringJournalEntry $recurringJournalEntry): void
    {
        $recurringJournalEntry->delete();
    }

    /**
     * Toggle active/inactive state.
     */
    public function toggle(RecurringJournalEntry $recurringJournalEntry): RecurringJournalEntry
    {
        $recurringJournalEntry->update([
            'is_active' => ! $recurringJournalEntry->is_active,
        ]);

        return $recurringJournalEntry->fresh();
    }

    /**
     * Process all due recurring journal entries.
     * For each due template, create a JournalEntry and auto-post it.
     * Each is wrapped in try/catch so one failure doesn't stop others.
     */
    public function processDue(): int
    {
        $due = RecurringJournalEntry::due()->get();
        $processed = 0;

        foreach ($due as $recurring) {
            try {
                $this->generateJournalEntry($recurring);
                $processed++;
            } catch (\Throwable $e) {
                Log::error("Failed to generate recurring journal entry #{$recurring->id}", [
                    'tenant_id' => $recurring->tenant_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $processed;
    }

    /**
     * Generate a single journal entry from a recurring template and auto-post it.
     */
    private function generateJournalEntry(RecurringJournalEntry $recurring): void
    {
        DB::transaction(function () use ($recurring): void {
            // Bind tenant context
            app()->instance('tenant.id', $recurring->tenant_id);

            // Build lines array from the template
            $lines = collect($recurring->lines)->map(fn (array $line) => [
                'account_id' => $line['account_id'],
                'debit' => $line['debit'] ?? 0,
                'credit' => $line['credit'] ?? 0,
                'description' => $line['description'] ?? null,
                'cost_center' => $line['cost_center'] ?? null,
            ])->all();

            // Create the journal entry via existing service
            $entry = $this->journalEntryService->create([
                'date' => now()->toDateString(),
                'description' => $recurring->description ?? $recurring->template_name_ar,
                'reference' => "RJE-{$recurring->id}",
                'lines' => $lines,
            ]);

            // Auto-post the entry
            $this->journalEntryService->post($entry);

            // Update recurring template
            $recurring->update([
                'last_run_date' => now()->toDateString(),
                'next_run_date' => $recurring->frequency->nextDate($recurring->next_run_date)->toDateString(),
                'run_count' => $recurring->run_count + 1,
            ]);
        });
    }
}

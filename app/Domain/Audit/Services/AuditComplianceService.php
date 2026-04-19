<?php

declare(strict_types=1);

namespace App\Domain\Audit\Services;

use App\Domain\Accounting\Models\JournalEntry;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

class AuditComplianceService
{
    /**
     * Who accessed the system, when, which actions.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function userAccessReport(array $filters): array
    {
        $tenantUserIds = $this->tenantUserIds();

        $query = Activity::query()
            ->whereIn('causer_id', $tenantUserIds)
            ->where('causer_type', (new User)->getMorphClass());

        $this->applyDateFilters($query, $filters);

        if (isset($filters['user_id'])) {
            $query->where('causer_id', $filters['user_id']);
        }

        $rows = (clone $query)
            ->select(
                'causer_id',
                DB::raw('COUNT(*) as actions_count'),
                DB::raw('MAX(created_at) as last_activity'),
            )
            ->groupBy('causer_id')
            ->get();

        // Login counts per user
        $loginCounts = (clone $query)
            ->where('description', 'like', '%logged in%')
            ->select('causer_id', DB::raw('COUNT(*) as login_count'))
            ->groupBy('causer_id')
            ->pluck('login_count', 'causer_id');

        // Models accessed per user
        $modelsAccessed = (clone $query)
            ->whereNotNull('subject_type')
            ->select('causer_id', 'subject_type')
            ->distinct()
            ->get()
            ->groupBy('causer_id')
            ->map(fn ($items) => $items->pluck('subject_type')->map(fn ($t) => class_basename($t))->values()->toArray());

        $userIds = $rows->pluck('causer_id')->toArray();
        $users = User::withoutGlobalScopes()->whereIn('id', $userIds)->get(['id', 'name'])->keyBy('id');

        $usersData = $rows->map(function ($row) use ($users, $loginCounts, $modelsAccessed) {
            $user = $users->get($row->causer_id);

            return [
                'user_id' => $row->causer_id,
                'name' => $user?->name,
                'login_count' => (int) ($loginCounts[$row->causer_id] ?? 0),
                'last_login' => $row->last_activity,
                'actions_count' => (int) $row->actions_count,
                'models_accessed' => $modelsAccessed[$row->causer_id] ?? [],
            ];
        })->values()->toArray();

        return [
            'period' => [
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
            ],
            'users' => $usersData,
        ];
    }

    /**
     * All data changes in period with old/new values.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function changeReport(array $filters): array
    {
        $tenantUserIds = $this->tenantUserIds();

        $query = Activity::query()
            ->with('causer:id,name')
            ->whereIn('causer_id', $tenantUserIds)
            ->where('causer_type', (new User)->getMorphClass())
            ->whereIn('event', ['created', 'updated', 'deleted']);

        $this->applyDateFilters($query, $filters);

        if (isset($filters['user_id'])) {
            $query->where('causer_id', $filters['user_id']);
        }
        if (isset($filters['model_type'])) {
            $query->where('subject_type', 'like', '%'.$filters['model_type'].'%');
        }

        $activities = $query->orderByDesc('created_at')
            ->paginate($filters['per_page'] ?? 50);

        $highRiskThreshold = $filters['threshold'] ?? '500000';

        $activities->getCollection()->transform(function (Activity $activity) use ($highRiskThreshold) {
            $properties = $activity->properties ?? collect();
            $oldValues = $properties->get('old', []);
            $newValues = $properties->get('attributes', []);

            $highRisk = $this->isHighRiskChange($oldValues, $newValues, $highRiskThreshold);

            return [
                'id' => $activity->id,
                'event' => $activity->event,
                'model' => $activity->subject_type ? class_basename($activity->subject_type) : null,
                'model_id' => $activity->subject_id,
                'user' => $activity->causer ? [
                    'id' => $activity->causer->id,
                    'name' => $activity->causer->name,
                ] : null,
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'high_risk' => $highRisk,
                'created_at' => $activity->created_at->toISOString(),
            ];
        });

        return [
            'period' => [
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
            ],
            'changes' => $activities,
        ];
    }

    /**
     * Flag suspicious / high-risk transactions.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function highRiskTransactions(array $filters): array
    {
        $tenantId = (int) app('tenant.id');
        $threshold = $filters['threshold'] ?? '500000';
        $flags = [];

        $jeQuery = JournalEntry::withoutGlobalScopes()
            ->where('tenant_id', $tenantId);

        if (isset($filters['from'])) {
            $jeQuery->where('date', '>=', $filters['from']);
        }
        if (isset($filters['to'])) {
            $jeQuery->where('date', '<=', $filters['to']);
        }

        // 1. Large journal entries (amount > threshold)
        $largeEntries = (clone $jeQuery)
            ->where(function ($q) use ($threshold) {
                $q->whereRaw('CAST(total_debit AS NUMERIC) > ?', [$threshold])
                    ->orWhereRaw('CAST(total_credit AS NUMERIC) > ?', [$threshold]);
            })
            ->get(['id', 'entry_number', 'date', 'total_debit', 'total_credit', 'created_by', 'status']);

        foreach ($largeEntries as $entry) {
            $maxAmount = bccomp((string) $entry->total_debit, (string) $entry->total_credit, 2) >= 0
                ? (string) $entry->total_debit
                : (string) $entry->total_credit;

            if (bccomp($maxAmount, $threshold, 2) > 0) {
                $flags[] = [
                    'type' => 'large_amount',
                    'severity' => 'high',
                    'journal_entry_id' => $entry->id,
                    'entry_number' => $entry->entry_number,
                    'amount' => $maxAmount,
                    'threshold' => $threshold,
                    'date' => $entry->date->toDateString(),
                ];
            }
        }

        // 2. Reversed entries
        $reversedEntries = (clone $jeQuery)
            ->whereNotNull('reversed_at')
            ->get(['id', 'entry_number', 'date', 'reversed_at', 'reversed_by', 'total_debit']);

        foreach ($reversedEntries as $entry) {
            $flags[] = [
                'type' => 'reversed_entry',
                'severity' => 'medium',
                'journal_entry_id' => $entry->id,
                'entry_number' => $entry->entry_number,
                'date' => $entry->date->toDateString(),
                'reversed_at' => $entry->reversed_at->toISOString(),
            ];
        }

        // 3. Back-dated entries (date < created_at)
        $allEntries = (clone $jeQuery)->get(['id', 'entry_number', 'date', 'created_at']);

        foreach ($allEntries as $entry) {
            if ($entry->date->lt($entry->created_at->startOfDay())) {
                $flags[] = [
                    'type' => 'back_dated',
                    'severity' => 'medium',
                    'journal_entry_id' => $entry->id,
                    'entry_number' => $entry->entry_number,
                    'entry_date' => $entry->date->toDateString(),
                    'created_at' => $entry->created_at->toISOString(),
                ];
            }
        }

        // 4. Same user created and posted (no segregation of duties)
        $sameUserEntries = (clone $jeQuery)
            ->whereNotNull('created_by')
            ->whereNotNull('posted_by')
            ->whereColumn('created_by', 'posted_by')
            ->get(['id', 'entry_number', 'date', 'created_by', 'posted_by']);

        foreach ($sameUserEntries as $entry) {
            $flags[] = [
                'type' => 'no_segregation',
                'severity' => 'high',
                'journal_entry_id' => $entry->id,
                'entry_number' => $entry->entry_number,
                'user_id' => $entry->created_by,
                'date' => $entry->date->toDateString(),
            ];
        }

        // 5. Mass deletions — check activity log for multiple deletes by same user in short window
        $tenantUserIds = $this->tenantUserIds();
        $deletions = Activity::query()
            ->whereIn('causer_id', $tenantUserIds)
            ->where('causer_type', (new User)->getMorphClass())
            ->where('event', 'deleted');

        if (isset($filters['from'])) {
            $deletions->where('created_at', '>=', $filters['from']);
        }
        if (isset($filters['to'])) {
            $deletions->where('created_at', '<=', $filters['to'].' 23:59:59');
        }

        $deletionsByUser = $deletions
            ->select('causer_id', DB::raw('COUNT(*) as deletion_count'))
            ->groupBy('causer_id')
            ->having(DB::raw('COUNT(*)'), '>=', 5)
            ->get();

        foreach ($deletionsByUser as $row) {
            $flags[] = [
                'type' => 'mass_deletion',
                'severity' => 'high',
                'user_id' => $row->causer_id,
                'deletion_count' => (int) $row->deletion_count,
            ];
        }

        return [
            'period' => [
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
            ],
            'threshold' => $threshold,
            'flags' => $flags,
            'total_flags' => count($flags),
        ];
    }

    /**
     * Check segregation of duties violations: same user created AND approved transactions.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function segregationOfDuties(array $filters): array
    {
        $tenantId = (int) app('tenant.id');

        $query = JournalEntry::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('created_by')
            ->whereNotNull('posted_by')
            ->whereColumn('created_by', 'posted_by');

        if (isset($filters['from'])) {
            $query->where('date', '>=', $filters['from']);
        }
        if (isset($filters['to'])) {
            $query->where('date', '<=', $filters['to']);
        }

        $violations = $query->get(['id', 'entry_number', 'date', 'created_by', 'posted_by', 'total_debit']);

        $userIds = $violations->pluck('created_by')->unique()->toArray();
        $users = User::withoutGlobalScopes()->whereIn('id', $userIds)->get(['id', 'name'])->keyBy('id');

        $violationData = $violations->map(function ($entry) use ($users) {
            $user = $users->get($entry->created_by);

            return [
                'journal_entry_id' => $entry->id,
                'entry_number' => $entry->entry_number,
                'date' => $entry->date->toDateString(),
                'user_id' => $entry->created_by,
                'user_name' => $user?->name,
                'amount' => (string) $entry->total_debit,
            ];
        })->values()->toArray();

        return [
            'period' => [
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
            ],
            'violations' => $violationData,
            'total_violations' => count($violationData),
        ];
    }

    /**
     * Generate CSV/JSON export of audit trail for external auditors.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function exportAuditTrail(array $filters): array
    {
        $tenantUserIds = $this->tenantUserIds();

        $query = Activity::query()
            ->with('causer:id,name,email')
            ->whereIn('causer_id', $tenantUserIds)
            ->where('causer_type', (new User)->getMorphClass());

        $this->applyDateFilters($query, $filters);

        if (isset($filters['user_id'])) {
            $query->where('causer_id', $filters['user_id']);
        }
        if (isset($filters['model_type'])) {
            $query->where('subject_type', 'like', '%'.$filters['model_type'].'%');
        }

        $activities = $query->orderBy('created_at')->get();

        $rows = $activities->map(function (Activity $activity) {
            $properties = $activity->properties ?? collect();
            $oldValues = $properties->get('old', []);
            $newValues = $properties->get('attributes', []);

            return [
                'id' => $activity->id,
                'timestamp' => $activity->created_at->toISOString(),
                'event' => $activity->event,
                'description' => $activity->description,
                'model_type' => $activity->subject_type ? class_basename($activity->subject_type) : null,
                'model_id' => $activity->subject_id,
                'user_id' => $activity->causer?->id,
                'user_name' => $activity->causer?->name,
                'user_email' => $activity->causer?->email,
                'old_values' => json_encode($oldValues),
                'new_values' => json_encode($newValues),
            ];
        })->toArray();

        $format = $filters['format'] ?? 'json';

        if ($format === 'csv') {
            return [
                'format' => 'csv',
                'headers' => array_keys($rows[0] ?? []),
                'rows' => $rows,
                'total' => count($rows),
            ];
        }

        return [
            'format' => 'json',
            'data' => $rows,
            'total' => count($rows),
        ];
    }

    /**
     * Dashboard summary data for compliance.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function complianceSummary(array $filters): array
    {
        $tenantUserIds = $this->tenantUserIds();

        $query = Activity::query()
            ->whereIn('causer_id', $tenantUserIds)
            ->where('causer_type', (new User)->getMorphClass());

        $this->applyDateFilters($query, $filters);

        $totalChanges = (clone $query)->whereIn('event', ['created', 'updated', 'deleted'])->count();

        // By model
        $byModel = (clone $query)
            ->whereNotNull('subject_type')
            ->select('subject_type', DB::raw('COUNT(*) as count'))
            ->groupBy('subject_type')
            ->get()
            ->map(fn ($row) => [
                'model' => class_basename($row->subject_type),
                'count' => (int) $row->count,
            ])
            ->sortByDesc('count')
            ->values()
            ->toArray();

        // By user
        $byUser = (clone $query)
            ->whereNotNull('causer_id')
            ->select('causer_id', DB::raw('COUNT(*) as count'))
            ->groupBy('causer_id')
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        $userIds = $byUser->pluck('causer_id')->toArray();
        $users = User::withoutGlobalScopes()->whereIn('id', $userIds)->get(['id', 'name'])->keyBy('id');

        $byUserData = $byUser->map(function ($row) use ($users) {
            $user = $users->get($row->causer_id);

            return [
                'user_id' => $row->causer_id,
                'name' => $user?->name,
                'count' => (int) $row->count,
            ];
        })->toArray();

        // High risk count
        $highRiskResult = $this->highRiskTransactions($filters);
        $highRiskCount = $highRiskResult['total_flags'];

        // Segregation violations count
        $segregationResult = $this->segregationOfDuties($filters);
        $segregationViolationsCount = $segregationResult['total_violations'];

        return [
            'period' => [
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
            ],
            'total_changes' => $totalChanges,
            'by_model' => $byModel,
            'by_user' => $byUserData,
            'high_risk_count' => $highRiskCount,
            'segregation_violations_count' => $segregationViolationsCount,
        ];
    }

    // ──────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────

    /**
     * @return Collection<int, int>
     */
    private function tenantUserIds(): Collection
    {
        $tenantId = (int) app('tenant.id');

        return User::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->pluck('id');
    }

    private function applyDateFilters(mixed $query, array $filters): void
    {
        if (isset($filters['from'])) {
            $query->where('created_at', '>=', $filters['from']);
        }
        if (isset($filters['to'])) {
            $query->where('created_at', '<=', $filters['to'].' 23:59:59');
        }
    }

    /**
     * Determine if a change is high-risk based on amount fields and status changes.
     */
    private function isHighRiskChange(array $oldValues, array $newValues, string $threshold): bool
    {
        // Check amount fields
        $amountFields = ['total_debit', 'total_credit', 'amount', 'total', 'subtotal', 'grand_total'];

        foreach ($amountFields as $field) {
            if (isset($newValues[$field]) && bccomp((string) $newValues[$field], $threshold, 2) > 0) {
                return true;
            }
        }

        // Check for reversals
        if (isset($newValues['status']) && $newValues['status'] === 'reversed') {
            return true;
        }

        // Check for cancellations
        if (isset($newValues['status']) && $newValues['status'] === 'cancelled') {
            return true;
        }

        return false;
    }
}

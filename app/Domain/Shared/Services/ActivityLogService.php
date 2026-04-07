<?php

declare(strict_types=1);

namespace App\Domain\Shared\Services;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

class ActivityLogService
{
    /**
     * List activity log entries scoped to the current tenant.
     *
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        $tenantId = (int) app('tenant.id');

        // Get user IDs belonging to this tenant for scoping
        $tenantUserIds = User::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->pluck('id');

        return Activity::query()
            ->with('causer:id,name,email')
            ->whereIn('causer_id', $tenantUserIds)
            ->where('causer_type', (new User)->getMorphClass())
            ->when(
                isset($filters['user_id']),
                fn ($q) => $q->where('causer_id', $filters['user_id']),
            )
            ->when(
                isset($filters['subject_type']),
                fn ($q) => $q->where('subject_type', $this->resolveSubjectType($filters['subject_type'])),
            )
            ->when(
                isset($filters['subject_id']),
                fn ($q) => $q->where('subject_id', $filters['subject_id']),
            )
            ->when(
                isset($filters['event']),
                fn ($q) => $q->where('event', $filters['event']),
            )
            ->when(
                isset($filters['from']),
                fn ($q) => $q->where('created_at', '>=', $filters['from']),
            )
            ->when(
                isset($filters['to']),
                fn ($q) => $q->where('created_at', '<=', $filters['to'] . ' 23:59:59'),
            )
            ->when(
                isset($filters['search']),
                fn ($q) => $q->where('description', 'ilike', "%{$filters['search']}%"),
            )
            ->orderByDesc('created_at')
            ->paginate($filters['per_page'] ?? 20);
    }

    /**
     * Get a single activity entry with full details.
     *
     * @return array<string, mixed>
     */
    public function detail(Activity $activity): array
    {
        $properties = $activity->properties ?? collect();
        $oldValues = $properties->get('old', []);
        $newValues = $properties->get('attributes', []);

        // Build a diff of changed fields
        $changes = [];
        foreach ($newValues as $key => $newVal) {
            $changes[] = [
                'field' => $key,
                'old' => $oldValues[$key] ?? null,
                'new' => $newVal,
            ];
        }

        return [
            'id' => $activity->id,
            'description' => $activity->description,
            'event' => $activity->event,
            'subject_type' => $activity->subject_type ? class_basename($activity->subject_type) : null,
            'subject_id' => $activity->subject_id,
            'causer' => $activity->causer ? [
                'id' => $activity->causer->id,
                'name' => $activity->causer->name,
                'email' => $activity->causer->email,
            ] : null,
            'changes' => $changes,
            'properties' => $properties->toArray(),
            'created_at' => $activity->created_at->toISOString(),
        ];
    }

    /**
     * Get activity log stats for the tenant.
     *
     * @return array<string, mixed>
     */
    public function stats(?string $from = null, ?string $to = null): array
    {
        $tenantId = (int) app('tenant.id');

        $tenantUserIds = User::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->pluck('id');

        $baseQuery = Activity::query()
            ->whereIn('causer_id', $tenantUserIds)
            ->where('causer_type', (new User)->getMorphClass());

        if ($from) {
            $baseQuery->where('created_at', '>=', $from);
        }
        if ($to) {
            $baseQuery->where('created_at', '<=', $to . ' 23:59:59');
        }

        // Changes by event type
        $byEvent = (clone $baseQuery)
            ->select('event', DB::raw('COUNT(*) as count'))
            ->groupBy('event')
            ->pluck('count', 'event')
            ->toArray();

        // Changes by model
        $byModel = (clone $baseQuery)
            ->select('subject_type', DB::raw('COUNT(*) as count'))
            ->whereNotNull('subject_type')
            ->groupBy('subject_type')
            ->get()
            ->map(fn ($row) => [
                'model' => class_basename($row->subject_type),
                'count' => $row->count,
            ])
            ->sortByDesc('count')
            ->values()
            ->take(10)
            ->toArray();

        // Most active users
        $byUserRows = (clone $baseQuery)
            ->select('causer_id', DB::raw('COUNT(*) as count'))
            ->whereNotNull('causer_id')
            ->groupBy('causer_id')
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        $userIds = $byUserRows->pluck('causer_id')->toArray();
        $users = User::withoutGlobalScopes()->whereIn('id', $userIds)->get(['id', 'name', 'email'])->keyBy('id');

        $byUser = $byUserRows->map(function ($row) use ($users) {
                $user = $users->get($row->causer_id);

                return [
                    'user_id' => $row->causer_id,
                    'name' => $user?->name,
                    'email' => $user?->email,
                    'count' => $row->count,
                ];
            })
            ->toArray();

        // Daily activity (last 30 days)
        $dailyActivity = (clone $baseQuery)
            ->where('created_at', '>=', now()->subDays(30))
            ->select(DB::raw("DATE(created_at) as date"), DB::raw('COUNT(*) as count'))
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date')
            ->pluck('count', 'date')
            ->toArray();

        return [
            'total_activities' => (clone $baseQuery)->count(),
            'by_event' => $byEvent,
            'by_model' => $byModel,
            'most_active_users' => $byUser,
            'daily_activity' => $dailyActivity,
        ];
    }

    /**
     * Resolve a short model name to its full class path.
     */
    private function resolveSubjectType(string $shortName): string
    {
        $map = [
            'Invoice' => \App\Domain\Billing\Models\Invoice::class,
            'Client' => \App\Domain\Client\Models\Client::class,
            'Account' => \App\Domain\Accounting\Models\Account::class,
            'JournalEntry' => \App\Domain\Accounting\Models\JournalEntry::class,
            'Payment' => \App\Domain\Billing\Models\Payment::class,
            'Document' => \App\Domain\Document\Models\Document::class,
            'Employee' => \App\Domain\Payroll\Models\Employee::class,
            'PayrollRun' => \App\Domain\Payroll\Models\PayrollRun::class,
            'TimesheetEntry' => \App\Domain\TimeTracking\Models\TimesheetEntry::class,
            'Tenant' => \App\Domain\Tenant\Models\Tenant::class,
            'User' => \App\Models\User::class,
            'Subscription' => \App\Domain\Subscription\Models\Subscription::class,
            'EtaDocument' => \App\Domain\EInvoice\Models\EtaDocument::class,
            'WebhookEndpoint' => \App\Domain\Webhook\Models\WebhookEndpoint::class,
            'BankReconciliation' => \App\Domain\Accounting\Models\BankReconciliation::class,
            'BlogPost' => \App\Domain\Blog\Models\BlogPost::class,
            'CmsPage' => \App\Domain\Cms\Models\CmsPage::class,
            'ContactSubmission' => \App\Domain\Cms\Models\ContactSubmission::class,
        ];

        return $map[$shortName] ?? $shortName;
    }
}

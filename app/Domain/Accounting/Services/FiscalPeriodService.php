<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\FiscalPeriod;
use App\Domain\Accounting\Models\FiscalYear;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FiscalPeriodService
{
    /**
     * List fiscal years with period count, ordered by start_date desc.
     *
     * @param  array<string, mixed>  $filters
     */
    public function listYears(array $filters = []): LengthAwarePaginator
    {
        return FiscalYear::query()
            ->withCount('periods')
            ->orderBy('start_date', 'desc')
            ->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Create a fiscal year and auto-generate 12 monthly periods.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function createYear(array $data): FiscalYear
    {
        // Validate no overlapping fiscal years for tenant
        $overlapping = FiscalYear::query()
            ->where(function ($q) use ($data): void {
                $q->whereBetween('start_date', [$data['start_date'], $data['end_date']])
                    ->orWhereBetween('end_date', [$data['start_date'], $data['end_date']])
                    ->orWhere(function ($q) use ($data): void {
                        $q->where('start_date', '<=', $data['start_date'])
                            ->where('end_date', '>=', $data['end_date']);
                    });
            })
            ->exists();

        if ($overlapping) {
            throw ValidationException::withMessages([
                'start_date' => ['This fiscal year overlaps with an existing fiscal year.'],
            ]);
        }

        return DB::transaction(function () use ($data): FiscalYear {
            $year = FiscalYear::query()->create($data);

            $startDate = Carbon::parse($data['start_date']);
            $endDate = Carbon::parse($data['end_date']);

            $periodStart = $startDate->copy();
            $periodNumber = 1;

            while ($periodStart->lt($endDate)) {
                $periodEnd = $periodStart->copy()->endOfMonth();

                // Don't exceed the fiscal year end date
                if ($periodEnd->gt($endDate)) {
                    $periodEnd = $endDate->copy();
                }

                $year->periods()->create([
                    'name' => $periodStart->format('F Y'),
                    'start_date' => $periodStart->toDateString(),
                    'end_date' => $periodEnd->toDateString(),
                    'period_number' => $periodNumber,
                    'is_closed' => false,
                ]);

                $periodStart = $periodEnd->copy()->addDay()->startOfDay();
                $periodNumber++;
            }

            return $year->load('periods');
        });
    }

    /**
     * Show a fiscal year with its periods loaded.
     */
    public function showYear(FiscalYear $year): FiscalYear
    {
        return $year->load('periods');
    }

    /**
     * Close a fiscal period (sequential closing enforced).
     *
     * @throws ValidationException
     */
    public function closePeriod(FiscalPeriod $period): FiscalPeriod
    {
        if ($period->is_closed) {
            throw ValidationException::withMessages([
                'period' => ['This period is already closed.'],
            ]);
        }

        // Validate all previous periods in the same fiscal year are closed
        $unclosedPrevious = FiscalPeriod::query()
            ->where('fiscal_year_id', $period->fiscal_year_id)
            ->where('period_number', '<', $period->period_number)
            ->where('is_closed', false)
            ->exists();

        if ($unclosedPrevious) {
            throw ValidationException::withMessages([
                'period' => ['All previous periods must be closed first.'],
            ]);
        }

        $period->update([
            'is_closed' => true,
            'closed_at' => now(),
            'closed_by' => Auth::id(),
        ]);

        return $period->refresh();
    }

    /**
     * Close a fiscal year (all periods must be closed).
     *
     * @throws ValidationException
     */
    public function closeYear(FiscalYear $year): FiscalYear
    {
        $unclosedPeriods = $year->periods()->where('is_closed', false)->exists();

        if ($unclosedPeriods) {
            throw ValidationException::withMessages([
                'year' => ['All periods must be closed before closing the fiscal year.'],
            ]);
        }

        $year->update([
            'is_closed' => true,
            'closed_at' => now(),
            'closed_by' => Auth::id(),
        ]);

        return $year->refresh();
    }

    /**
     * Reopen a fiscal period (only the last closed one can be reopened).
     *
     * @throws ValidationException
     */
    public function reopenPeriod(FiscalPeriod $period): FiscalPeriod
    {
        if (! $period->is_closed) {
            throw ValidationException::withMessages([
                'period' => ['This period is not closed.'],
            ]);
        }

        // Validate no subsequent periods are closed
        $closedSubsequent = FiscalPeriod::query()
            ->where('fiscal_year_id', $period->fiscal_year_id)
            ->where('period_number', '>', $period->period_number)
            ->where('is_closed', true)
            ->exists();

        if ($closedSubsequent) {
            throw ValidationException::withMessages([
                'period' => ['Cannot reopen this period because a subsequent period is closed. Reopen the later period first.'],
            ]);
        }

        // If fiscal year is closed, reopen it too
        $year = $period->fiscalYear;

        if ($year->is_closed) {
            $year->update([
                'is_closed' => false,
                'closed_at' => null,
                'closed_by' => null,
            ]);
        }

        $period->update([
            'is_closed' => false,
            'closed_at' => null,
            'closed_by' => null,
        ]);

        return $period->refresh();
    }

    /**
     * Find the fiscal period containing the given date for the current tenant.
     */
    public function findPeriodForDate(string $date): ?FiscalPeriod
    {
        return FiscalPeriod::query()
            ->containingDate($date)
            ->first();
    }
}

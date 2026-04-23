<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Exceptions\FiscalPeriodLockedException;
use App\Domain\Accounting\Models\FiscalPeriod;
use App\Domain\Accounting\Models\JournalEntry;
use Illuminate\Support\Carbon;

/**
 * Fiscal period locking service.
 * Prevents modifications to journal entries in closed/locked fiscal periods.
 *
 * Usage:
 *   FiscalPeriodLockService::assertPeriodOpen($tenantId, $date);
 *   // Throws FiscalPeriodLockedException if period is closed.
 */
class FiscalPeriodLockService
{
    /**
     * Check if a date falls within an open fiscal period.
     * Throws exception if the period is closed/locked.
     *
     * @throws FiscalPeriodLockedException
     */
    public static function assertPeriodOpen(int $tenantId, string|Carbon $date): void
    {
        $date = $date instanceof Carbon ? $date : Carbon::parse($date);

        $period = FiscalPeriod::where('tenant_id', $tenantId)
            ->where('start_date', '<=', $date->toDateString())
            ->where('end_date', '>=', $date->toDateString())
            ->first();

        if (! $period) {
            // No period defined for this date — allow (period tracking may not be set up yet)
            return;
        }

        if ($period->is_locked || $period->is_closed) {
            throw new FiscalPeriodLockedException(
                "Cannot modify entries in a closed fiscal period ({$period->start_date->format('Y/m')} — {$period->end_date->format('Y/m')})."
            );
        }
    }

    /**
     * Check without throwing (returns boolean).
     */
    public static function isPeriodOpen(int $tenantId, string|Carbon $date): bool
    {
        try {
            self::assertPeriodOpen($tenantId, $date);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Lock a fiscal period (prevents all modifications).
     */
    public static function lockPeriod(FiscalPeriod $period): void
    {
        $period->update([
            'is_locked' => true,
            'locked_at' => now(),
            'locked_by' => auth()->id(),
        ]);
    }

    /**
     * Unlock a fiscal period (re-open for edits — admin only).
     */
    public static function unlockPeriod(FiscalPeriod $period): void
    {
        $period->update([
            'is_locked' => false,
            'locked_at' => null,
            'locked_by' => null,
        ]);
    }

    /**
     * Get a summary of entries in a period (for lock confirmation).
     */
    public static function periodSummary(FiscalPeriod $period): array
    {
        $entries = JournalEntry::where('tenant_id', $period->tenant_id)
            ->whereBetween('date', [$period->start_date, $period->end_date]);

        return [
            'period' => "{$period->start_date->format('Y/m/d')} — {$period->end_date->format('Y/m/d')}",
            'total_entries' => $entries->count(),
            'is_locked' => $period->is_locked ?? false,
            'locked_at' => $period->locked_at ?? null,
        ];
    }
}

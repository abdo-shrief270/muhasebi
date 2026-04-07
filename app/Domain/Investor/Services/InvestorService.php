<?php

declare(strict_types=1);

namespace App\Domain\Investor\Services;

use App\Domain\Investor\Models\Investor;
use App\Domain\Investor\Models\InvestorTenantShare;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class InvestorService
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        return Investor::query()
            ->withCount('tenantShares')
            ->when(isset($filters['is_active']), fn ($q) => $q->where('is_active', $filters['is_active']))
            ->when(
                isset($filters['search']),
                fn ($q) => $q->where(function ($q) use ($filters): void {
                    $q->where('name', 'ilike', "%{$filters['search']}%")
                        ->orWhere('email', 'ilike', "%{$filters['search']}%");
                })
            )
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 15);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Investor
    {
        return Investor::query()->create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Investor $investor, array $data): Investor
    {
        $investor->update($data);

        return $investor->refresh();
    }

    public function delete(Investor $investor): void
    {
        $investor->delete();
    }

    // ──────────────────────────────────────
    // Tenant Shares
    // ──────────────────────────────────────

    /**
     * Get all tenant shares for an investor.
     */
    public function getShares(Investor $investor): Collection
    {
        return $investor->tenantShares()->with('tenant')->get();
    }

    /**
     * Set/update an investor's ownership percentage for a tenant.
     *
     * @throws ValidationException
     */
    public function setShare(Investor $investor, int $tenantId, float $percentage): InvestorTenantShare
    {
        if ($percentage <= 0 || $percentage > 100) {
            throw ValidationException::withMessages([
                'ownership_percentage' => [
                    'Ownership percentage must be between 0.01 and 100.',
                    'نسبة الملكية يجب أن تكون بين 0.01 و 100.',
                ],
            ]);
        }

        // Check total ownership for this tenant doesn't exceed 100%
        $existingTotal = (float) InvestorTenantShare::query()
            ->where('tenant_id', $tenantId)
            ->where('investor_id', '!=', $investor->id)
            ->sum('ownership_percentage');

        if (($existingTotal + $percentage) > 100) {
            throw ValidationException::withMessages([
                'ownership_percentage' => [
                    "Total ownership for this tenant would exceed 100%. Current total by others: {$existingTotal}%.",
                    "إجمالي الملكية لهذا الحساب سيتجاوز 100%. الإجمالي الحالي للآخرين: {$existingTotal}%.",
                ],
            ]);
        }

        return InvestorTenantShare::query()->updateOrCreate(
            ['investor_id' => $investor->id, 'tenant_id' => $tenantId],
            ['ownership_percentage' => $percentage],
        );
    }

    /**
     * Remove an investor's share for a tenant.
     */
    public function removeShare(Investor $investor, int $tenantId): void
    {
        InvestorTenantShare::query()
            ->where('investor_id', $investor->id)
            ->where('tenant_id', $tenantId)
            ->delete();
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Investor\Services;

use App\Domain\Investor\Enums\DistributionStatus;
use App\Domain\Investor\Models\Investor;
use App\Domain\Investor\Models\InvestorTenantShare;
use App\Domain\Investor\Models\ProfitDistribution;
use App\Domain\Subscription\Models\SubscriptionPayment;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class ProfitDistributionService
{
    /**
     * List distributions with filters.
     *
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        return ProfitDistribution::query()
            ->with(['investor', 'tenant'])
            ->when(isset($filters['investor_id']), fn ($q) => $q->forInvestor($filters['investor_id']))
            ->when(isset($filters['tenant_id']), fn ($q) => $q->where('tenant_id', $filters['tenant_id']))
            ->when(
                isset($filters['month']) && isset($filters['year']),
                fn ($q) => $q->forPeriod($filters['month'], $filters['year'])
            )
            ->when(isset($filters['status']), fn ($q) => $q->ofStatus(DistributionStatus::from($filters['status'])))
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Calculate distributions for a given month.
     * Auto-pulls revenue from SubscriptionPayment. Expenses provided per tenant.
     *
     * @param  array<int, float>  $expensesPerTenant  [tenant_id => expenses_amount]
     * @return Collection<int, ProfitDistribution>
     *
     * @throws ValidationException
     */
    public function calculate(int $month, int $year, array $expensesPerTenant): Collection
    {
        return DB::transaction(function () use ($month, $year, $expensesPerTenant): Collection {
            // Get all active investor-tenant shares
            $shares = InvestorTenantShare::query()
                ->whereHas('investor', fn ($q) => $q->where('is_active', true))
                ->with(['investor', 'tenant'])
                ->get();

            if ($shares->isEmpty()) {
                throw ValidationException::withMessages([
                    'investors' => [
                        'No active investor-tenant shares found.',
                        'لا توجد حصص مستثمرين نشطة.',
                    ],
                ]);
            }

            $created = collect();

            foreach ($shares as $share) {
                $tenantId = $share->tenant_id;
                $investorId = $share->investor_id;

                // Check for existing distribution
                $exists = ProfitDistribution::query()
                    ->where('investor_id', $investorId)
                    ->where('tenant_id', $tenantId)
                    ->forPeriod($month, $year)
                    ->exists();

                if ($exists) {
                    continue;
                }

                // Auto-pull revenue from completed subscription payments
                $periodStart = sprintf('%04d-%02d-01', $year, $month);
                $periodEnd = date('Y-m-t', strtotime($periodStart));

                $tenantRevenue = (float) SubscriptionPayment::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('status', 'completed')
                    ->whereBetween('paid_at', [$periodStart, $periodEnd . ' 23:59:59'])
                    ->sum('amount');

                $tenantExpenses = $expensesPerTenant[$tenantId] ?? 0.0;
                $netProfit = round($tenantRevenue - $tenantExpenses, 2);
                $investorShareAmount = round(max(0, $netProfit) * (float) $share->ownership_percentage / 100, 2);

                $distribution = ProfitDistribution::query()->create([
                    'investor_id' => $investorId,
                    'tenant_id' => $tenantId,
                    'month' => $month,
                    'year' => $year,
                    'tenant_revenue' => $tenantRevenue,
                    'tenant_expenses' => $tenantExpenses,
                    'net_profit' => $netProfit,
                    'ownership_percentage' => $share->ownership_percentage,
                    'investor_share' => $investorShareAmount,
                    'status' => DistributionStatus::Draft,
                ]);

                $created->push($distribution);
            }

            // Load relations on each model individually (Collection::load doesn't exist on base Collection)
            return $created->each(fn ($d) => $d->load(['investor', 'tenant']));
        });
    }

    /**
     * @throws ValidationException
     */
    public function approve(ProfitDistribution $distribution): ProfitDistribution
    {
        if (! $distribution->status->canApprove()) {
            throw ValidationException::withMessages([
                'status' => [
                    'Only draft distributions can be approved.',
                    'يمكن اعتماد التوزيعات المسودة فقط.',
                ],
            ]);
        }

        $distribution->update(['status' => DistributionStatus::Approved]);

        return $distribution->refresh();
    }

    /**
     * @throws ValidationException
     */
    public function markPaid(ProfitDistribution $distribution): ProfitDistribution
    {
        if (! $distribution->status->canMarkPaid()) {
            throw ValidationException::withMessages([
                'status' => [
                    'Only approved distributions can be marked as paid.',
                    'يمكن تحديد التوزيعات المعتمدة كمدفوعة فقط.',
                ],
            ]);
        }

        $distribution->update([
            'status' => DistributionStatus::Paid,
            'paid_at' => now(),
        ]);

        return $distribution->refresh();
    }

    /**
     * @throws ValidationException
     */
    public function delete(ProfitDistribution $distribution): void
    {
        if (! $distribution->status->canDelete()) {
            throw ValidationException::withMessages([
                'status' => [
                    'Only draft distributions can be deleted.',
                    'يمكن حذف التوزيعات المسودة فقط.',
                ],
            ]);
        }

        $distribution->delete();
    }

    /**
     * Generate a monthly payslip PDF for an investor.
     */
    public function generatePayslip(Investor $investor, int $month, int $year): Response
    {
        $distributions = ProfitDistribution::query()
            ->where('investor_id', $investor->id)
            ->forPeriod($month, $year)
            ->with('tenant')
            ->get();

        $totalRevenue = (float) $distributions->sum('tenant_revenue');
        $totalExpenses = (float) $distributions->sum('tenant_expenses');
        $totalNetProfit = (float) $distributions->sum('net_profit');
        $totalShare = (float) $distributions->sum('investor_share');

        $pdf = Pdf::loadView('reports.investor-payslip', [
            'investor' => $investor,
            'distributions' => $distributions,
            'month' => $month,
            'year' => $year,
            'totalRevenue' => $totalRevenue,
            'totalExpenses' => $totalExpenses,
            'totalNetProfit' => $totalNetProfit,
            'totalShare' => $totalShare,
            'generatedAt' => now()->format('Y-m-d H:i'),
        ]);

        $pdf->setPaper('a4', 'portrait');

        return $pdf->download("investor-payslip-{$investor->name}-{$month}-{$year}.pdf");
    }
}

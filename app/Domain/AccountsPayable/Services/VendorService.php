<?php

declare(strict_types=1);

namespace App\Domain\AccountsPayable\Services;

use App\Domain\AccountsPayable\Models\Vendor;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class VendorService
{
    /**
     * List vendors with search, filter, and pagination.
     *
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        // Whitelist sort columns so user input can't reach an unindexed column.
        $allowedSorts = ['name_ar', 'name_en', 'code', 'city', 'created_at'];
        $sortBy = in_array($filters['sort_by'] ?? null, $allowedSorts, true)
            ? $filters['sort_by']
            : 'created_at';
        $sortDir = (($filters['sort_dir'] ?? 'desc') === 'asc') ? 'asc' : 'desc';

        return Vendor::query()
            ->when(isset($filters['search']), fn ($q) => $q->search($filters['search']))
            ->when(isset($filters['is_active']), fn ($q) => $q->where('is_active', (bool) $filters['is_active']))
            ->withCount('bills')
            ->orderBy($sortBy, $sortDir)
            ->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Create a new vendor.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Vendor
    {
        return Vendor::query()->create([
            'tenant_id' => (int) app('tenant.id'),
            'name_ar' => $data['name_ar'],
            'name_en' => $data['name_en'] ?? null,
            'code' => $data['code'] ?? null,
            'tax_id' => $data['tax_id'] ?? null,
            'commercial_register' => $data['commercial_register'] ?? null,
            'vat_registration' => $data['vat_registration'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'address_ar' => $data['address_ar'] ?? null,
            'address_en' => $data['address_en'] ?? null,
            'city' => $data['city'] ?? null,
            'country' => $data['country'] ?? 'EG',
            'bank_name' => $data['bank_name'] ?? null,
            'bank_account' => $data['bank_account'] ?? null,
            'iban' => $data['iban'] ?? null,
            'swift_code' => $data['swift_code'] ?? null,
            'payment_terms' => $data['payment_terms'] ?? 'net_30',
            'credit_limit' => $data['credit_limit'] ?? 0,
            'currency' => $data['currency'] ?? 'EGP',
            'contacts' => $data['contacts'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => auth()->id(),
        ]);
    }

    /**
     * Update an existing vendor.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Vendor $vendor, array $data): Vendor
    {
        $vendor->update(array_filter($data, fn ($v) => $v !== null));

        return $vendor->refresh();
    }

    /**
     * Delete a vendor if it has no active bills.
     *
     * @throws ValidationException
     */
    public function delete(Vendor $vendor): void
    {
        if ($vendor->bills()->whereNotIn('status', ['draft', 'cancelled'])->exists()) {
            throw ValidationException::withMessages([
                'vendor' => ['Cannot delete vendor with active bills.'],
            ]);
        }

        $vendor->delete();
    }

    /**
     * Compute the per-vendor financial summary used by VendorController::show()
     * for the SPA's detail page (balance, open bills count, aging buckets,
     * last payment timestamp). Buckets follow the SPA contract:
     *   0_30 = open bills with due_date in the future or up to 30 days overdue
     *   31_60 / 61_90 / 90_plus
     *
     * @return array{
     *   balance: string,
     *   open_bills_count: int,
     *   aging_buckets: array{'0_30': string, '31_60': string, '61_90': string, '90_plus': string},
     *   last_payment_at: ?string,
     * }
     */
    public function vendorSummary(Vendor $vendor): array
    {
        $openBills = $vendor->bills()
            ->whereIn('status', ['approved', 'partially_paid'])
            ->get(['id', 'total', 'amount_paid', 'due_date']);

        $today = now()->startOfDay();
        $balance = '0.00';
        $buckets = ['0_30' => '0.00', '31_60' => '0.00', '61_90' => '0.00', '90_plus' => '0.00'];
        $openCount = 0;

        foreach ($openBills as $bill) {
            $remaining = bcsub((string) $bill->total, (string) $bill->amount_paid, 2);
            if (bccomp($remaining, '0', 2) <= 0) {
                continue;
            }
            $openCount++;
            $balance = bcadd($balance, $remaining, 2);

            $daysOverdue = (int) max(0, $bill->due_date->startOfDay()->diffInDays($today, false));
            $key = match (true) {
                $daysOverdue <= 30 => '0_30',
                $daysOverdue <= 60 => '31_60',
                $daysOverdue <= 90 => '61_90',
                default            => '90_plus',
            };
            $buckets[$key] = bcadd($buckets[$key], $remaining, 2);
        }

        $lastPaymentAt = $vendor->payments()
            ->latest('payment_date')
            ->value('payment_date');

        return [
            'balance' => $balance,
            'open_bills_count' => $openCount,
            'aging_buckets' => $buckets,
            'last_payment_at' => $lastPaymentAt?->toIso8601String(),
        ];
    }

    /**
     * Generate a vendor statement showing bills and payments within a date range.
     *
     * @return array{vendor: array<string, mixed>, period: array<string, ?string>, transactions: array<int, array<string, mixed>>, balance: string}
     */
    public function statement(Vendor $vendor, ?string $from = null, ?string $to = null): array
    {
        $query = $vendor->bills()
            ->with('payments')
            ->whereNotIn('status', ['draft', 'cancelled']);

        if ($from) {
            $query->where('date', '>=', $from);
        }

        if ($to) {
            $query->where('date', '<=', $to);
        }

        $bills = $query->orderBy('date')->get();

        $runningBalance = '0.00';
        $transactions = [];

        foreach ($bills as $bill) {
            $runningBalance = bcadd($runningBalance, (string) $bill->total, 2);
            $transactions[] = [
                'date' => $bill->date->toDateString(),
                'type' => 'bill',
                'reference' => $bill->bill_number,
                'description' => $bill->vendor_invoice_number ?? $bill->notes,
                'debit' => (string) $bill->total,
                'credit' => '0.00',
                'balance' => $runningBalance,
            ];

            foreach ($bill->payments as $payment) {
                $runningBalance = bcsub($runningBalance, (string) $payment->amount, 2);
                $transactions[] = [
                    'date' => $payment->payment_date->toDateString(),
                    'type' => 'payment',
                    'reference' => $payment->reference ?? "PAY-{$payment->id}",
                    'description' => $payment->notes,
                    'debit' => '0.00',
                    'credit' => (string) $payment->amount,
                    'balance' => $runningBalance,
                ];
            }
        }

        return [
            'vendor' => [
                'id' => $vendor->id,
                'name_ar' => $vendor->name_ar,
                'name_en' => $vendor->name_en,
                'code' => $vendor->code,
            ],
            'period' => ['from' => $from, 'to' => $to],
            'transactions' => $transactions,
            'balance' => $runningBalance,
        ];
    }

    /**
     * Generate an aging report for unpaid vendor bills.
     *
     * @param  array<string, mixed>  $filters
     * @return array{rows: array<int, array<string, mixed>>, totals: array<string, string>, generated_at: string}
     */
    public function agingReport(array $filters = []): array
    {
        $vendors = Vendor::query()
            ->with(['bills' => fn ($q) => $q->whereIn('status', ['approved', 'partially_paid'])])
            ->when(isset($filters['vendor_id']), fn ($q) => $q->where('id', $filters['vendor_id']))
            ->active()
            ->get();

        $today = now();
        $rows = [];
        $totals = ['current' => '0.00', 'days_30' => '0.00', 'days_60' => '0.00', 'days_90' => '0.00', 'over_90' => '0.00', 'total' => '0.00'];

        foreach ($vendors as $vendor) {
            $buckets = ['current' => '0.00', 'days_30' => '0.00', 'days_60' => '0.00', 'days_90' => '0.00', 'over_90' => '0.00'];

            foreach ($vendor->bills as $bill) {
                $balance = bcsub((string) $bill->total, (string) $bill->amount_paid, 2);

                if (bccomp($balance, '0', 2) <= 0) {
                    continue;
                }

                $daysOverdue = max(0, $bill->due_date->diffInDays($today, false));

                if ($daysOverdue <= 0) {
                    $buckets['current'] = bcadd($buckets['current'], $balance, 2);
                } elseif ($daysOverdue <= 30) {
                    $buckets['days_30'] = bcadd($buckets['days_30'], $balance, 2);
                } elseif ($daysOverdue <= 60) {
                    $buckets['days_60'] = bcadd($buckets['days_60'], $balance, 2);
                } elseif ($daysOverdue <= 90) {
                    $buckets['days_90'] = bcadd($buckets['days_90'], $balance, 2);
                } else {
                    $buckets['over_90'] = bcadd($buckets['over_90'], $balance, 2);
                }
            }

            $vendorTotal = bcadd(
                bcadd(
                    bcadd(
                        bcadd($buckets['current'], $buckets['days_30'], 2),
                        $buckets['days_60'],
                        2
                    ),
                    $buckets['days_90'],
                    2
                ),
                $buckets['over_90'],
                2
            );

            if (bccomp($vendorTotal, '0', 2) <= 0) {
                continue;
            }

            $rows[] = array_merge(
                [
                    'vendor_id' => $vendor->id,
                    'name_ar' => $vendor->name_ar,
                    'name_en' => $vendor->name_en,
                    'code' => $vendor->code,
                ],
                $buckets,
                ['total' => $vendorTotal]
            );

            foreach ($buckets as $k => $v) {
                $totals[$k] = bcadd($totals[$k], $v, 2);
            }
            $totals['total'] = bcadd($totals['total'], $vendorTotal, 2);
        }

        return [
            'rows' => $rows,
            'totals' => $totals,
            'generated_at' => now()->format('Y-m-d H:i'),
        ];
    }
}

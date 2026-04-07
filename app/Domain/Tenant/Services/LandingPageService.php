<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Services;

use App\Domain\Shared\Enums\UserRole;
use App\Domain\Subscription\Models\Plan;
use App\Domain\Tenant\Models\Tenant;
use App\Models\User;

class LandingPageService
{
    /**
     * Aggregate all data needed for the tenant landing page.
     *
     * @return array<string, mixed>
     */
    public function getPageData(Tenant $tenant): array
    {
        $locale = request()->query('lang', $tenant->settings['locale'] ?? 'ar');

        $team = User::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereIn('role', [UserRole::Admin, UserRole::Accountant, UserRole::Auditor])
            ->where('is_active', true)
            ->select('name', 'role')
            ->orderBy('role')
            ->get()
            ->map(fn ($user) => [
                'name' => $user->name,
                'role' => $locale === 'ar' ? $user->role->labelAr() : $user->role->label(),
            ]);

        $plans = Plan::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($plan) => [
                'name' => $locale === 'ar' ? $plan->name_ar : $plan->name_en,
                'description' => $locale === 'ar' ? ($plan->description_ar ?? '') : ($plan->description_en ?? ''),
                'price_monthly' => $plan->price_monthly,
                'price_annual' => $plan->price_annual,
                'features' => $plan->features ?? [],
            ]);

        $services = $tenant->settings['services'] ?? [];

        return [
            'tenant' => $tenant,
            'team' => $team,
            'plans' => $plans,
            'services' => $services,
            'locale' => $locale,
            'dir' => $locale === 'ar' ? 'rtl' : 'ltr',
        ];
    }

    /**
     * Update tenant landing page branding.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateBranding(Tenant $tenant, array $data): Tenant
    {
        $tenant->update($data);

        return $tenant->refresh();
    }
}

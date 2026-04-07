<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Subscription\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name_en' => 'Free Trial',
                'name_ar' => 'تجربة مجانية',
                'slug' => 'free_trial',
                'description_en' => 'Try Muhasebi free for 14 days with limited features.',
                'description_ar' => 'جرب محاسبي مجاناً لمدة 14 يوماً مع ميزات محدودة.',
                'price_monthly' => 0,
                'price_annual' => 0,
                'currency' => 'EGP',
                'trial_days' => 14,
                'limits' => [
                    'max_users' => 2,
                    'max_clients' => 10,
                    'max_storage_bytes' => 536870912,
                    'max_invoices_per_month' => 20,
                ],
                'features' => [
                    'e_invoice' => false,
                    'api_access' => false,
                    'custom_reports' => false,
                    'client_portal' => false,
                    'priority_support' => false,
                ],
                'is_active' => true,
                'sort_order' => 0,
            ],
            [
                'name_en' => 'Starter',
                'name_ar' => 'أساسي',
                'slug' => 'starter',
                'description_en' => 'Perfect for freelancers and small businesses getting started.',
                'description_ar' => 'مثالي للمستقلين والشركات الصغيرة في بداياتها.',
                'price_monthly' => 299,
                'price_annual' => 2990,
                'currency' => 'EGP',
                'trial_days' => 14,
                'limits' => [
                    'max_users' => 5,
                    'max_clients' => 50,
                    'max_storage_bytes' => 2147483648,
                    'max_invoices_per_month' => 200,
                ],
                'features' => [
                    'e_invoice' => false,
                    'api_access' => false,
                    'custom_reports' => true,
                    'client_portal' => false,
                    'priority_support' => false,
                ],
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'name_en' => 'Professional',
                'name_ar' => 'احترافي',
                'slug' => 'professional',
                'description_en' => 'Advanced features for growing businesses and teams.',
                'description_ar' => 'ميزات متقدمة للشركات والفرق المتنامية.',
                'price_monthly' => 599,
                'price_annual' => 5990,
                'currency' => 'EGP',
                'trial_days' => 14,
                'limits' => [
                    'max_users' => 15,
                    'max_clients' => 200,
                    'max_storage_bytes' => 10737418240,
                    'max_invoices_per_month' => 1000,
                ],
                'features' => [
                    'e_invoice' => true,
                    'api_access' => true,
                    'custom_reports' => true,
                    'client_portal' => true,
                    'priority_support' => false,
                ],
                'is_active' => true,
                'sort_order' => 2,
            ],
            [
                'name_en' => 'Enterprise',
                'name_ar' => 'مؤسسات',
                'slug' => 'enterprise',
                'description_en' => 'Full-featured plan for large organizations with priority support.',
                'description_ar' => 'خطة شاملة للمؤسسات الكبيرة مع دعم فني متميز.',
                'price_monthly' => 1499,
                'price_annual' => 14990,
                'currency' => 'EGP',
                'trial_days' => 30,
                'limits' => [
                    'max_users' => 100,
                    'max_clients' => -1,
                    'max_storage_bytes' => 53687091200,
                    'max_invoices_per_month' => -1,
                ],
                'features' => [
                    'e_invoice' => true,
                    'api_access' => true,
                    'custom_reports' => true,
                    'client_portal' => true,
                    'priority_support' => true,
                ],
                'is_active' => true,
                'sort_order' => 3,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(
                ['slug' => $plan['slug']],
                $plan,
            );
        }
    }
}

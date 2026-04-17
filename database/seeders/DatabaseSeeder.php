<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,       // 1. Roles & permissions (required by users)
            RolesSeeder::class,            // 2. Tenant-level roles (admin/accountant/auditor/limited)
            PlanSeeder::class,             // 3. Subscription plans
            SuperAdminSeeder::class,       // 4. Super admin user
            DemoTenantSeeder::class,       // 5. Demo tenant with sample data
            SyncUserRolesSeeder::class,    // 6. Sync enum roles → Spatie roles
            CmsSeeder::class,              // 7. Landing page, pages, testimonials, FAQs
            BlogSeeder::class,             // 8. Blog categories, tags, sample posts
            CurrencySeeder::class,         // 9. Currencies + initial exchange rates
        ]);
    }
}

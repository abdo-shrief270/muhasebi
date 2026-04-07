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
            PlanSeeder::class,             // 2. Subscription plans
            SuperAdminSeeder::class,       // 3. Super admin user
            DemoTenantSeeder::class,       // 4. Demo tenant with sample data
            SyncUserRolesSeeder::class,    // 5. Sync enum roles → Spatie roles
            CmsSeeder::class,              // 6. Landing page, pages, testimonials, FAQs
            BlogSeeder::class,             // 7. Blog categories, tags, sample posts
            CurrencySeeder::class,         // 8. Currencies + initial exchange rates
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Shared\Enums\TenantStatus;
use App\Domain\Shared\Enums\UserRole;
use App\Domain\Tenant\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoTenantSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::query()->updateOrCreate(
            ['slug' => 'demo-firm'],
            [
                'name' => 'شركة المحاسبة التجريبية',
                'email' => 'info@demo-firm.muhasebi.com',
                'phone' => '+201234567890',
                'tax_id' => '123456789',
                'commercial_register' => '12345',
                'address' => '١٢ شارع التحرير، وسط البلد',
                'city' => 'القاهرة',
                'status' => TenantStatus::Active,
                'settings' => [
                    'locale' => 'ar',
                    'timezone' => 'Africa/Cairo',
                    'currency' => 'EGP',
                    'fiscal_year_start' => '01-01',
                ],
            ]
        );

        // Admin user for the demo tenant
        User::query()->updateOrCreate(
            ['email' => 'admin@demo-firm.muhasebi.com'],
            [
                'tenant_id' => $tenant->id,
                'name' => 'أحمد المحاسب',
                'password' => Hash::make('Demo@2026!'),
                'role' => UserRole::Admin,
                'locale' => 'ar',
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        // Accountant user
        User::query()->updateOrCreate(
            ['email' => 'accountant@demo-firm.muhasebi.com'],
            [
                'tenant_id' => $tenant->id,
                'name' => 'سارة المحاسبة',
                'password' => Hash::make('Demo@2026!'),
                'role' => UserRole::Accountant,
                'locale' => 'ar',
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        // Client user
        User::query()->updateOrCreate(
            ['email' => 'client@demo-firm.muhasebi.com'],
            [
                'tenant_id' => $tenant->id,
                'name' => 'محمد العميل',
                'password' => Hash::make('Demo@2026!'),
                'role' => UserRole::Client,
                'locale' => 'ar',
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        // Seed Egyptian Chart of Accounts for the demo tenant
        new EgyptianCoASeeder()->run($tenant->id);
    }
}

<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Observers\AccountObserver;
use App\Domain\Accounting\Observers\JournalEntryObserver;
use App\Domain\Auth\Services\PermissionService;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Observers\InvoiceObserver;
use App\Domain\Billing\Observers\PaymentObserver;
use App\Domain\Shared\Models\FeatureFlag;
use App\Domain\Shared\Observers\FeatureFlagObserver;
use App\Domain\Subscription\Models\Plan;
use App\Domain\Subscription\Models\Subscription;
use App\Domain\Subscription\Observers\PlanObserver;
use App\Domain\Subscription\Observers\SubscriptionObserver;
use App\Domain\AccountsPayable\Models\Bill;
use App\Domain\AccountsPayable\Models\Vendor;
use App\Domain\Expense\Models\Expense;
use App\Domain\Expense\Models\ExpenseCategory;
use App\Domain\FixedAsset\Models\AssetCategory;
use App\Domain\FixedAsset\Models\FixedAsset;
use App\Domain\Client\Models\Client;
use App\Domain\Accounting\Models\CostCenter;
use App\Domain\Collection\Models\CollectionAction;
use App\Domain\Document\Models\Document;
use App\Domain\Payroll\Models\Employee;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Tax\Models\TaxReturn;
use App\Domain\Tax\Models\WhtCertificate;
use App\Domain\Shared\Enums\UserRole;
use App\Domain\Shared\Models\FeatureFlag;
use App\Domain\Shared\Services\QueryAnalyzer;
use App\Domain\Subscription\Models\Plan;
use App\Domain\Subscription\Models\Subscription;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\TimeTracking\Models\TimesheetEntry;
use App\Models\User;
use App\Policies\AccountPolicy;
use App\Policies\CollectionActionPolicy;
use App\Policies\AssetCategoryPolicy;
use App\Policies\BillPolicy;
use App\Policies\ClientPolicy;
use App\Policies\DocumentPolicy;
use App\Policies\EmployeePolicy;
use App\Policies\ExpenseCategoryPolicy;
use App\Policies\ExpensePolicy;
use App\Policies\FixedAssetPolicy;
use App\Policies\FiscalYearPolicy;
use App\Policies\InvoicePolicy;
use App\Policies\JournalEntryPolicy;
use App\Policies\PaymentPolicy;
use App\Policies\PayrollRunPolicy;
use App\Policies\SuperAdmin\FeatureFlagPolicy as SuperAdminFeatureFlagPolicy;
use App\Policies\SuperAdmin\PlanPolicy as SuperAdminPlanPolicy;
use App\Policies\SuperAdmin\SubscriptionPolicy as SuperAdminSubscriptionPolicy;
use App\Policies\SuperAdmin\TenantPolicy as SuperAdminTenantPolicy;
use App\Policies\SuperAdmin\UserPolicy as SuperAdminUserPolicy;
use App\Policies\TaxReturnPolicy;
use App\Policies\TimesheetEntryPolicy;
use App\Policies\CostCenterPolicy;
use App\Policies\VendorPolicy;
use App\Policies\WhtCertificatePolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind a default null tenant context
        $this->app->singleton('tenant', fn () => null);
        $this->app->singleton('tenant.id', fn () => null);
    }

    public function boot(): void
    {
        $this->registerPolicies();
        $this->registerPermissionGates();
        $this->configureRateLimiting();
        $this->registerModelObservers();

        // Register N+1 query analyzer (works in all environments)
        QueryAnalyzer::register(threshold: 5);

        // Enforce strict model behavior in non-production
        Model::shouldBeStrict(! $this->app->isProduction());

        // Prevent lazy loading in non-production
        Model::preventLazyLoading(! $this->app->isProduction());

        // Prevent silently discarding attributes
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        // Log slow queries in non-production
        if (! $this->app->isProduction()) {
            DB::listen(function ($query): void {
                if ($query->time > 500) {
                    logger()->warning('Slow query detected', [
                        'sql' => $query->sql,
                        'time' => $query->time.'ms',
                        'bindings' => $query->bindings,
                    ]);
                }
            });
        }
    }

    private function configureRateLimiting(): void
    {
        // Default API rate limit: 60 requests/minute for guests, 120 for authenticated
        RateLimiter::for('api', function (Request $request) {
            return $request->user()
                ? Limit::perMinute(120)->by($request->user()->id)
                : Limit::perMinute(60)->by($request->ip());
        });

        // Stricter limit for contact form (5 per minute per IP)
        RateLimiter::for('contact', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        // Public landing/blog (generous but bounded)
        RateLimiter::for('public', function (Request $request) {
            return Limit::perMinute(120)->by($request->ip());
        });

        // Admin operations
        RateLimiter::for('admin', function (Request $request) {
            return Limit::perMinute(200)->by($request->user()?->id ?: $request->ip());
        });

        // SuperAdmin panel login: 5 attempts per minute per IP. Used by the
        // `throttle:admin-login` middleware on Filament's login POST route.
        RateLimiter::for('admin-login', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        // Expensive operations — apply via throttle:reports, throttle:exports, throttle:imports middleware
        RateLimiter::for('reports', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('exports', function (Request $request) {
            return Limit::perMinute(5)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('imports', function (Request $request) {
            return Limit::perMinute(3)->by($request->user()?->id ?: $request->ip());
        });
    }

    private function registerModelObservers(): void
    {
        Invoice::observe(InvoiceObserver::class);
        Payment::observe(PaymentObserver::class);
        JournalEntry::observe(JournalEntryObserver::class);
        Account::observe(AccountObserver::class);
        Plan::observe(PlanObserver::class);
        Subscription::observe(SubscriptionObserver::class);
        FeatureFlag::observe(FeatureFlagObserver::class);
    }

    private function registerPolicies(): void
    {
        Gate::policy(Account::class, AccountPolicy::class);
        Gate::policy(AssetCategory::class, AssetCategoryPolicy::class);
        Gate::policy(Bill::class, BillPolicy::class);
        Gate::policy(Client::class, ClientPolicy::class);
        Gate::policy(CollectionAction::class, CollectionActionPolicy::class);
        Gate::policy(CostCenter::class, CostCenterPolicy::class);
        Gate::policy(Document::class, DocumentPolicy::class);
        Gate::policy(Employee::class, EmployeePolicy::class);
        Gate::policy(Expense::class, ExpensePolicy::class);
        Gate::policy(ExpenseCategory::class, ExpenseCategoryPolicy::class);
        Gate::policy(FixedAsset::class, FixedAssetPolicy::class);
        Gate::policy(FiscalYear::class, FiscalYearPolicy::class);
        Gate::policy(Invoice::class, InvoicePolicy::class);
        Gate::policy(JournalEntry::class, JournalEntryPolicy::class);
        Gate::policy(Payment::class, PaymentPolicy::class);
        Gate::policy(PayrollRun::class, PayrollRunPolicy::class);
        Gate::policy(TaxReturn::class, TaxReturnPolicy::class);
        Gate::policy(TimesheetEntry::class, TimesheetEntryPolicy::class);
        Gate::policy(Vendor::class, VendorPolicy::class);
        Gate::policy(WhtCertificate::class, WhtCertificatePolicy::class);

        // SuperAdmin panel policies (Filament v5 /admin).
        // Gate::before() above grants SuperAdmin a blanket bypass; these are a
        // defensive second layer in case the bypass is ever scoped down.
        Gate::policy(Tenant::class, SuperAdminTenantPolicy::class);
        Gate::policy(Plan::class, SuperAdminPlanPolicy::class);
        Gate::policy(Subscription::class, SuperAdminSubscriptionPolicy::class);
        Gate::policy(FeatureFlag::class, SuperAdminFeatureFlagPolicy::class);
        Gate::policy(User::class, SuperAdminUserPolicy::class);
    }

    private function registerPermissionGates(): void
    {
        // SuperAdmin bypasses all permission checks
        Gate::before(fn (User $user) => $user->role === UserRole::SuperAdmin ? true : null);

        // Spatie's HasRoles trait + register_permission_check_method=true in config
        // auto-registers $user->can('permission') via Gate. For permissions not yet in DB
        // (e.g. before seeding), fall back to config-based check.
        Gate::after(function (User $user, string $ability, ?bool $result) {
            if ($result !== null) {
                return $result;
            }

            // Fallback: check config-based permissions if Gate didn't resolve
            return PermissionService::hasPermission($user, $ability) ?: null;
        });
    }
}

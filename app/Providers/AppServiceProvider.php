<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Auth\Services\PermissionService;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;
use App\Domain\Client\Models\Client;
use App\Domain\Document\Models\Document;
use App\Domain\Payroll\Models\Employee;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Shared\Enums\UserRole;
use App\Domain\TimeTracking\Models\TimesheetEntry;
use App\Models\User;
use App\Policies\AccountPolicy;
use App\Policies\ClientPolicy;
use App\Policies\DocumentPolicy;
use App\Policies\EmployeePolicy;
use App\Policies\FiscalYearPolicy;
use App\Policies\InvoicePolicy;
use App\Policies\JournalEntryPolicy;
use App\Policies\PaymentPolicy;
use App\Policies\PayrollRunPolicy;
use App\Policies\TimesheetEntryPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Cache\RateLimiting\Limit;
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
        \App\Domain\Shared\Services\QueryAnalyzer::register(threshold: 5);

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
                        'time' => $query->time . 'ms',
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
        Invoice::observe(\App\Domain\Billing\Observers\InvoiceObserver::class);
        Payment::observe(\App\Domain\Billing\Observers\PaymentObserver::class);
    }

    private function registerPolicies(): void
    {
        Gate::policy(Account::class, AccountPolicy::class);
        Gate::policy(Client::class, ClientPolicy::class);
        Gate::policy(Document::class, DocumentPolicy::class);
        Gate::policy(Employee::class, EmployeePolicy::class);
        Gate::policy(FiscalYear::class, FiscalYearPolicy::class);
        Gate::policy(Invoice::class, InvoicePolicy::class);
        Gate::policy(JournalEntry::class, JournalEntryPolicy::class);
        Gate::policy(Payment::class, PaymentPolicy::class);
        Gate::policy(PayrollRun::class, PayrollRunPolicy::class);
        Gate::policy(TimesheetEntry::class, TimesheetEntryPolicy::class);
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

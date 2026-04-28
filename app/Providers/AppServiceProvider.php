<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\CostCenter;
use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Observers\AccountObserver;
use App\Domain\Accounting\Observers\JournalEntryObserver;
use App\Domain\AccountsPayable\Models\Bill;
use App\Domain\AccountsPayable\Models\Vendor;
use App\Domain\Auth\Services\PermissionService;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Observers\InvoiceLineObserver;
use App\Domain\Billing\Observers\InvoiceObserver;
use App\Domain\AccountsPayable\Observers\BillLineObserver;
use App\Domain\AccountsPayable\Models\BillLine;
use App\Domain\Billing\Models\InvoiceLine;
use App\Domain\Billing\Observers\PaymentObserver;
use App\Domain\Client\Models\Client;
use App\Domain\Collection\Models\CollectionAction;
use App\Domain\Document\Models\Document;
use App\Domain\Expense\Models\Expense;
use App\Domain\Expense\Models\ExpenseCategory;
use App\Domain\FixedAsset\Models\AssetCategory;
use App\Domain\FixedAsset\Models\FixedAsset;
use App\Domain\Payroll\Models\Employee;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Shared\Enums\UserRole;
use App\Domain\Shared\Models\FeatureFlag;
use App\Domain\Shared\Observers\FeatureFlagObserver;
use App\Domain\Shared\Services\QueryAnalyzer;
use App\Domain\Subscription\Models\Plan;
use App\Domain\Subscription\Models\Subscription;
use App\Domain\Subscription\Observers\PlanObserver;
use App\Domain\Subscription\Observers\SubscriptionObserver;
use App\Domain\Tax\Models\TaxReturn;
use App\Domain\Tax\Models\WhtCertificate;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\TimeTracking\Models\TimesheetEntry;
use App\Models\User;
use App\Policies\AccountPolicy;
use App\Policies\AssetCategoryPolicy;
use App\Policies\BillPolicy;
use App\Policies\ClientPolicy;
use App\Policies\CollectionActionPolicy;
use App\Policies\CostCenterPolicy;
use App\Policies\DocumentPolicy;
use App\Policies\EmployeePolicy;
use App\Policies\ExpenseCategoryPolicy;
use App\Policies\ExpensePolicy;
use App\Policies\FiscalYearPolicy;
use App\Policies\FixedAssetPolicy;
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
use App\Policies\VendorPolicy;
use App\Policies\WhtCertificatePolicy;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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
        // Factories live at database/factories/{Basename}Factory.php regardless
        // of the model's namespace (App\Domain\Foo\Models\Bar). Laravel's default
        // resolver assumes App\Models\* layout, so map by basename instead — lets
        // us drop the per-model newFactory() overrides that were added piecemeal.
        Factory::guessFactoryNamesUsing(function (string $modelName): string {
            $basename = class_basename($modelName);

            return "Database\\Factories\\{$basename}Factory";
        });

        $this->registerPolicies();
        $this->registerPermissionGates();
        $this->configureRateLimiting();
        $this->registerModelObservers();
        $this->configurePasswordPolicy();
        $this->configurePasswordResetUrl();

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

        // Messaging (WhatsApp / SMS via Beon.chat). 30/min per user is a
        // middle ground between accidental spam and legitimate bulk reminder
        // workflows — raise here if firms hit the ceiling during
        // end-of-month collection runs.
        RateLimiter::for('messaging', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });
    }

    private function registerModelObservers(): void
    {
        Invoice::observe(InvoiceObserver::class);
        // Recency tracking on saved per-party items — bumps
        // client_products.last_used_at / vendor_products.last_used_at on
        // line creation so the catalog "Last used" column and the picker's
        // recent-first ordering surface real data.
        InvoiceLine::observe(InvoiceLineObserver::class);
        BillLine::observe(BillLineObserver::class);
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

    /**
     * One global password policy used by every password-setting endpoint via
     * Password::defaults(). Production-strength in prod; min(8) in local/testing
     * so existing test fixtures ("password", "password123", etc.) keep working
     * without having to rewrite them. LoginRequest keeps its own min:8 since
     * login is just a sanity check — it never validates the stored password
     * against the current policy.
     */
    private function configurePasswordPolicy(): void
    {
        Password::defaults(function () {
            if ($this->app->isProduction()) {
                return Password::min(10)
                    ->mixedCase()
                    ->letters()
                    ->numbers()
                    ->symbols()
                    ->uncompromised();
            }

            return Password::min(8);
        });
    }

    /**
     * Build the reset-password link that ships in the notification email so it
     * points at the SPA's reset page (`/auth/reset-password`) instead of the
     * non-existent backend `password.reset` named route. The SPA reads `token`
     * and `email` from query params and posts them to /v1/reset-password.
     *
     * Base URL falls back to APP_URL; override per-deployment with
     * SPA_RESET_PASSWORD_URL when the SPA lives on a different host.
     *
     * The email body itself is also localized here via toMailUsing(): subject
     * and lines come from `lang/{ar,en}/emails.php` and we honor the user's
     * stored `locale` (falls back to app locale, then `ar`) so the email
     * language matches the user — not whatever request happened to trigger
     * the notification. Without toMailUsing(), Laravel renders the framework
     * default English-only template.
     */
    private function configurePasswordResetUrl(): void
    {
        ResetPassword::createUrlUsing(function ($notifiable, string $token): string {
            $base = config('app.spa_reset_password_url')
                ?: rtrim((string) config('app.url'), '/').'/auth/reset-password';

            return $base.'?'.http_build_query([
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ]);
        });

        ResetPassword::toMailUsing(function ($notifiable, string $token): MailMessage {
            $url = call_user_func(ResetPassword::$createUrlCallback, $notifiable, $token);

            $previousLocale = app()->getLocale();
            $userLocale = $notifiable->locale ?? $previousLocale;
            $locale = in_array($userLocale, ['ar', 'en'], true) ? $userLocale : 'ar';
            app()->setLocale($locale);

            $appName = config('app.name', 'Muhasebi');
            $expiresMinutes = config('auth.passwords.users.expire', 60);

            $message = (new MailMessage)
                ->subject(__('emails.reset_password.subject', ['app' => $appName]))
                ->greeting(__('emails.reset_password.greeting', ['name' => $notifiable->name ?? '']))
                ->line(__('emails.reset_password.line_intro'))
                ->action(__('emails.reset_password.action'), $url)
                ->line(__('emails.reset_password.line_expires', ['count' => $expiresMinutes]))
                ->line(__('emails.reset_password.line_ignore'))
                ->salutation(__('emails.reset_password.salutation', ['app' => $appName]));

            // Restore the request's app locale once the message is built so we
            // don't leak the user-locale switch into anything that runs after
            // notification dispatch (e.g. observers, subsequent toasts).
            app()->setLocale($previousLocale);

            return $message;
        });
    }

    private function registerPermissionGates(): void
    {
        // SuperAdmin bypasses all permission checks
        Gate::before(fn (User $user) => $user->role === UserRole::SuperAdmin ? true : null);

        // opcodesio/log-viewer enforces this gate in production. Without it the
        // panel returns 403 even for authenticated SuperAdmins.
        Gate::define('viewLogViewer', fn (?User $user) => $user?->role === UserRole::SuperAdmin);

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

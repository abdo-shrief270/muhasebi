<?php

declare(strict_types=1);

use App\Domain\Auth\Controllers\AuthController;
use App\Domain\Billing\Services\InvoiceGuardService;
use App\Domain\Integration\Services\BeonChatService;
use App\Domain\Tenant\Models\Tenant;
use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AccountSuggestionController;
use App\Http\Controllers\Api\V1\ActivityLogController;
use App\Http\Controllers\Api\V1\AuditComplianceController;
use App\Http\Controllers\Api\V1\Admin\AdminActivityLogController;
use App\Http\Controllers\Api\V1\Admin\AdminApiLogController;
use App\Http\Controllers\Api\V1\Admin\AdminAuditLogController;
use App\Http\Controllers\Api\V1\Admin\AdminBatchController;
use App\Http\Controllers\Api\V1\Admin\AdminBlogController;
use App\Http\Controllers\Api\V1\Admin\AdminCmsController;
use App\Http\Controllers\Api\V1\Admin\AdminDashboardController;
use App\Http\Controllers\Api\V1\Admin\AdminEmailTemplateController;
use App\Http\Controllers\Api\V1\Admin\AdminFeatureFlagController;
use App\Http\Controllers\Api\V1\Admin\AdminIntegrationController;
use App\Http\Controllers\Api\V1\Admin\AdminInvestorController;
use App\Http\Controllers\Api\V1\Admin\AdminMediaController;
use App\Http\Controllers\Api\V1\Admin\AdminMetricsController;
use App\Http\Controllers\Api\V1\Admin\AdminPlatformSettingsController;
use App\Http\Controllers\Api\V1\Admin\AdminProfitDistributionController;
use App\Http\Controllers\Api\V1\Admin\AdminRoleController;
use App\Http\Controllers\Api\V1\Admin\AdminSubscriptionController;
use App\Http\Controllers\Api\V1\Admin\AdminTenantController;
use App\Http\Controllers\Api\V1\Admin\AdminUsageController;
use App\Http\Controllers\Api\V1\Admin\AdminUserController;
use App\Http\Controllers\Api\V1\AgingReminderController;
use App\Http\Controllers\Api\V1\AnomalyDetectionController;
use App\Http\Controllers\Api\V1\ApiDocsController;
use App\Http\Controllers\Api\V1\BankAutoReconciliationController;
use App\Http\Controllers\Api\V1\BankCategorizationController;
use App\Http\Controllers\Api\V1\BankReconciliationController;
use App\Http\Controllers\Api\V1\AssetCategoryController;
use App\Http\Controllers\Api\V1\AssetDisposalController;
use App\Http\Controllers\Api\V1\BillController;
use App\Http\Controllers\Api\V1\BillPaymentController;
use App\Http\Controllers\Api\V1\BlogController;
use App\Http\Controllers\Api\V1\BudgetController;
use App\Http\Controllers\Api\V1\ClientController;
use App\Http\Controllers\Api\V1\CollectionController;
use App\Http\Controllers\Api\V1\CsvImportController;
use App\Http\Controllers\Api\V1\CostCenterController;
use App\Http\Controllers\Api\V1\CurrencyController;
use App\Http\Controllers\Api\V1\CustomReportController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DeviceTokenController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\ExecutiveDashboardController;
use App\Http\Controllers\Api\V1\ECommerceController;
use App\Http\Controllers\Api\V1\EtaController;
use App\Http\Controllers\Api\V1\EtaItemCodeController;
use App\Http\Controllers\Api\V1\ExpenseCategoryController;
use App\Http\Controllers\Api\V1\ExpenseController;
use App\Http\Controllers\Api\V1\ExpenseReportController;
use App\Http\Controllers\Api\V1\ExportController;
use App\Http\Controllers\Api\V1\FiscalPeriodController;
use App\Http\Controllers\Api\V1\FiscalYearController;
use App\Http\Controllers\Api\V1\FixedAssetController;
use App\Http\Controllers\Api\V1\FxRevaluationController;
use App\Http\Controllers\Api\V1\HealthCheckController;
use App\Http\Controllers\Api\V1\ImportController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\InvoiceSettingsController;
use App\Http\Controllers\Api\V1\JournalEntryController;
use App\Http\Controllers\Api\V1\LaborLawController;
use App\Http\Controllers\Api\V1\SocialInsuranceController;
use App\Http\Controllers\Api\V1\LandingController;
use App\Http\Controllers\Api\V1\LandingPageSettingsController;
use App\Http\Controllers\Api\V1\MessagingController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\NotificationPreferenceController;
use App\Http\Controllers\Api\V1\OnboardingController;
use App\Http\Controllers\Api\V1\OnboardingWizardController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\LeaveController;
use App\Http\Controllers\Api\V1\LoanController;
use App\Http\Controllers\Api\V1\PayrollController;
use App\Http\Controllers\Api\V1\PayslipController;
use App\Http\Controllers\Api\V1\SalaryComponentController;
use App\Http\Controllers\Api\V1\PlanController;
use App\Http\Controllers\Api\V1\Portal\ClientPortalController;
use App\Http\Controllers\Api\V1\Portal\ClientPortalDocumentController;
use App\Http\Controllers\Api\V1\Portal\ClientPortalInvoiceController;
use App\Http\Controllers\Api\V1\Portal\ClientPortalMessageController;
use App\Http\Controllers\Api\V1\Portal\ClientPortalNotificationController;
use App\Http\Controllers\Api\V1\RecurringInvoiceController;
use App\Http\Controllers\Api\V1\RecurringJournalEntryController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\ScheduledReportController;
use App\Http\Controllers\Api\V1\RssFeedController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\TeamController;
use App\Http\Controllers\Api\V1\TimeBillingController;
use App\Http\Controllers\Api\V1\TaxReturnController;
use App\Http\Controllers\Api\V1\TimerController;
use App\Http\Controllers\Api\V1\TimesheetController;
use App\Http\Controllers\Api\V1\TwoFactorController;
use App\Http\Controllers\Api\V1\UserPreferenceController;
use App\Http\Controllers\Api\V1\VendorController;
use App\Http\Controllers\Api\V1\WebhookController;
use App\Http\Controllers\Api\V1\ApprovalController;
use App\Http\Controllers\Api\V1\ApprovalWorkflowController;
use App\Http\Controllers\Api\V1\EngagementController;
use App\Http\Controllers\Api\V1\WebhookEndpointController;
use App\Http\Controllers\Api\V1\WhtCertificateController;
use App\Http\Controllers\Api\V1\WorkingPaperController;
use App\Http\Controllers\Api\V1\AlertRuleController;
use App\Http\Controllers\Api\V1\BankConnectionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — v1
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function (): void {

    // ──────────────────────────────────────
    // Public (no auth required)
    // ──────────────────────────────────────
    Route::post('/register', [AuthController::class, 'register'])
        ->middleware('throttle:5,1')
        ->name('auth.register');

    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1')
        ->name('auth.login');

    // Public plans (pricing page)
    Route::get('/plans', [PlanController::class, 'index'])->name('plans.index');
    Route::get('/plans/{plan}', [PlanController::class, 'show'])->name('plans.show');

    // Public landing page data (cached responses)
    Route::middleware('cache.public:600')->group(function (): void {
        Route::get('/landing', [LandingController::class, 'index'])->name('landing.index');
        Route::get('/pages/{slug}', [LandingController::class, 'showPage'])->name('pages.show');
    });
    Route::post('/contact', [LandingController::class, 'submitContact'])->middleware('throttle:5,1')->name('contact.submit');

    // Health check
    Route::get('/health', HealthCheckController::class)->name('health');

    // API Documentation
    Route::get('/docs', [ApiDocsController::class, 'ui'])->name('docs.ui');
    Route::get('/docs/spec', [ApiDocsController::class, 'spec'])->name('docs.spec');

    // Public blog
    Route::prefix('blog')->name('blog.')->group(function (): void {
        Route::get('/rss', RssFeedController::class)->name('rss');
        Route::get('/', [BlogController::class, 'index'])->name('index');
        Route::get('/featured', [BlogController::class, 'featured'])->name('featured');
        Route::get('/categories', [BlogController::class, 'categories'])->name('categories');
        Route::get('/tags', [BlogController::class, 'tags'])->name('tags');
        Route::get('/search', [BlogController::class, 'search'])->name('search');
        Route::get('/{slug}', [BlogController::class, 'show'])->name('show');
    });

    // E-commerce webhooks (public, verified by platform signature)
    Route::post('/webhooks/ecommerce/{platform}', [ECommerceController::class, 'webhook'])->name('webhooks.ecommerce');

    // Payment gateway webhooks (no auth, verified by signature)
    Route::post('/webhooks/paymob', [WebhookController::class, 'paymob'])->name('webhooks.paymob');
    Route::post('/webhooks/fawry', [WebhookController::class, 'fawry'])->name('webhooks.fawry');

    // Beon.chat webhook (incoming messages, signature-verified)
    Route::post('/webhooks/beon-chat', function (Request $request) {
        $signature = $request->header('X-Beon-Signature') ?? $request->header('X-Webhook-Signature');
        if (! $signature) {
            Log::warning('BeonChat webhook: missing signature', ['ip' => $request->ip()]);

            return response()->json(['error' => 'Missing signature'], 401);
        }
        $result = BeonChatService::handleWebhook($request->all(), $signature);

        return response()->json($result);
    })->name('webhooks.beon-chat');

    // ──────────────────────────────────────
    // Authenticated
    // ──────────────────────────────────────
    Route::middleware('auth:sanctum')->group(function (): void {

        Route::post('/logout', [AuthController::class, 'logout'])
            ->name('auth.logout');

        Route::get('/me', [AuthController::class, 'me'])
            ->name('auth.me');

        Route::put('/profile', [AuthController::class, 'updateProfile'])
            ->name('auth.update-profile');

        Route::post('/change-password', [AuthController::class, 'changePassword'])
            ->middleware('throttle:3,1')
            ->name('auth.change-password');

        // ── Two-Factor Authentication ──
        Route::prefix('2fa')->name('2fa.')->group(function (): void {
            Route::get('status', [TwoFactorController::class, 'status'])->name('status');
            Route::post('enable', [TwoFactorController::class, 'enable'])->name('enable');
            Route::post('disable', [TwoFactorController::class, 'disable'])->middleware('throttle:3,1')->name('disable');
            Route::post('verify', [TwoFactorController::class, 'verify'])->middleware('throttle:5,1')->name('verify');
        });

        // ── Notification Preferences (per-user, not tenant-scoped) ──
        Route::get('notification-preferences', [NotificationPreferenceController::class, 'index'])->name('notification-preferences.index');
        Route::put('notification-preferences', [NotificationPreferenceController::class, 'update'])->name('notification-preferences.update');

        // ── User Preferences (theme, shortcuts, display) ──
        Route::prefix('preferences')->name('preferences.')->group(function (): void {
            Route::get('/', [UserPreferenceController::class, 'show'])->name('show');
            Route::put('/', [UserPreferenceController::class, 'update'])->name('update');
            Route::post('reset', [UserPreferenceController::class, 'reset'])->name('reset');
            Route::get('shortcuts', [UserPreferenceController::class, 'shortcuts'])->name('shortcuts');
        });

        // ── Device Tokens (Push Notifications) ──
        Route::get('device-tokens', [DeviceTokenController::class, 'index'])->name('device-tokens.index');
        Route::post('device-tokens', [DeviceTokenController::class, 'store'])->name('device-tokens.store');
        Route::delete('device-tokens', [DeviceTokenController::class, 'destroy'])->name('device-tokens.destroy');

        // ──────────────────────────────────
        // Tenant-scoped routes
        // ──────────────────────────────────
        Route::middleware(['tenant', 'active', 'enforce.2fa', 'set_timezone', 'set_locale', 'meter.usage'])->group(function (): void {

            // ── Dashboard (all roles) ──
            Route::middleware('permission:view_dashboard')->group(function (): void {
                Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
            });

            // ── Activity Log (all authenticated — view audit trail) ──
            Route::prefix('activity-log')->name('activity-log.')->group(function (): void {
                Route::get('/', [ActivityLogController::class, 'index'])->name('index');
                Route::get('stats', [ActivityLogController::class, 'stats'])->name('stats');
                Route::get('{activityId}', [ActivityLogController::class, 'show'])->name('show');
            });

            // ── Audit Compliance (admin only — view_audit permission) ──
            Route::middleware(['feature:audit_log', 'permission:view_audit'])->prefix('audit-compliance')->name('audit-compliance.')->group(function (): void {
                Route::get('user-access', [AuditComplianceController::class, 'userAccess'])->name('user-access');
                Route::get('changes', [AuditComplianceController::class, 'changes'])->name('changes');
                Route::get('high-risk', [AuditComplianceController::class, 'highRisk'])->name('high-risk');
                Route::get('segregation', [AuditComplianceController::class, 'segregation'])->name('segregation');
                Route::get('export', [AuditComplianceController::class, 'export'])->name('export');
                Route::get('summary', [AuditComplianceController::class, 'summary'])->name('summary');
            });

            // ── Notifications (all authenticated — no permission needed) ──
            Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
            Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount'])->name('notifications.unread-count');
            Route::post('notifications/{notification}/read', [NotificationController::class, 'markAsRead'])->name('notifications.read');
            Route::post('notifications/read-all', [NotificationController::class, 'markAllAsRead'])->name('notifications.read-all');
            Route::delete('notifications/{notification}', [NotificationController::class, 'destroy'])->name('notifications.destroy');

            // ── Subscription (admin only) ──
            Route::middleware('permission:manage_subscription')->group(function (): void {
                Route::get('subscription', [SubscriptionController::class, 'show'])->name('subscription.show');
                Route::post('subscription/subscribe', [SubscriptionController::class, 'subscribe'])->middleware(['throttle:5,1', 'idempotent', 'no-duplicate'])->name('subscription.subscribe');
                Route::post('subscription/cancel', [SubscriptionController::class, 'cancel'])->middleware(['throttle:5,1', 'no-duplicate'])->name('subscription.cancel');
                Route::post('subscription/renew', [SubscriptionController::class, 'renew'])->middleware(['throttle:5,1', 'idempotent', 'no-duplicate'])->name('subscription.renew');
                Route::post('subscription/change-plan', [SubscriptionController::class, 'changePlan'])->middleware('throttle:5,1')->name('subscription.change-plan');
                Route::get('subscription/usage', [SubscriptionController::class, 'usage'])->name('subscription.usage');
                Route::get('subscription/usage-history', [SubscriptionController::class, 'usageHistory'])->name('subscription.usage-history');
                Route::get('subscription/payments', [SubscriptionController::class, 'payments'])->name('subscription.payments');
            });

            // ── Clients (admin + accountant) ──
            Route::middleware(['feature:clients', 'permission:manage_clients'])->group(function (): void {
                Route::apiResource('clients', ClientController::class);
                Route::post('clients/{client}/restore', [ClientController::class, 'restore'])->name('clients.restore');
                Route::patch('clients/{client}/toggle-active', [ClientController::class, 'toggleActive'])->name('clients.toggle-active');
                Route::get('clients/{client}/messages', [ClientController::class, 'messages'])->name('clients.messages');
                Route::post('clients/{client}/messages', [ClientController::class, 'sendMessage'])->name('clients.send-message');
                Route::post('import/clients', [CsvImportController::class, 'importClients'])->name('import.clients');
            });
            Route::middleware(['feature:client_portal', 'permission:invite_client_portal'])->group(function (): void {
                Route::post('clients/{client}/invite-portal', [ClientController::class, 'invitePortalUser'])->name('clients.invite-portal');
            });

            // ── Chart of Accounts (admin + accountant) ──
            Route::middleware(['feature:accounting', 'permission:manage_accounts'])->group(function (): void {
                // ── Account Suggestions (AI categorization) ──
                Route::get('account-suggestions', [AccountSuggestionController::class, 'suggest'])->name('account-suggestions.suggest');
                Route::post('account-suggestions/train', [AccountSuggestionController::class, 'train'])->name('account-suggestions.train');
            });

            Route::middleware(['feature:accounting', 'permission:manage_accounts'])->group(function (): void {
                Route::get('accounts/tree', [AccountController::class, 'tree'])->name('accounts.tree');
                Route::apiResource('accounts', AccountController::class);
                Route::post('import/accounts', [CsvImportController::class, 'importAccounts'])->name('import.accounts');

                // Bank Reconciliation
                Route::prefix('bank-reconciliations')->name('bank-reconciliations.')->group(function (): void {
                    Route::get('/', [BankReconciliationController::class, 'index'])->name('index');
                    Route::post('/', [BankReconciliationController::class, 'store'])->name('store');
                    Route::get('{bankReconciliation}', [BankReconciliationController::class, 'show'])->name('show');
                    Route::delete('{bankReconciliation}', [BankReconciliationController::class, 'destroy'])->name('destroy');
                    Route::get('{bankReconciliation}/summary', [BankReconciliationController::class, 'summary'])->name('summary');
                    Route::post('{bankReconciliation}/import', [BankReconciliationController::class, 'importLines'])->name('import');
                    Route::post('{bankReconciliation}/auto-match', [BankReconciliationController::class, 'autoMatch'])->name('auto-match');
                    Route::post('{bankReconciliation}/complete', [BankReconciliationController::class, 'complete'])->name('complete');
                    Route::post('lines/{bankStatementLine}/match', [BankReconciliationController::class, 'manualMatch'])->name('lines.match');
                    Route::post('lines/{bankStatementLine}/unmatch', [BankReconciliationController::class, 'unmatch'])->name('lines.unmatch');
                    Route::post('lines/{bankStatementLine}/exclude', [BankReconciliationController::class, 'exclude'])->name('lines.exclude');
                    Route::post('{bankReconciliation}/categorize', [BankCategorizationController::class, 'categorize'])->name('categorize');

                    // Auto-reconciliation (smart matching)
                    Route::post('{bankReconciliation}/smart-match', [BankAutoReconciliationController::class, 'smartMatch'])->name('smart-match');
                    Route::post('{bankReconciliation}/auto-post', [BankAutoReconciliationController::class, 'autoPost'])->name('auto-post');
                });

                // Bank Statement Line auto-reconciliation actions
                Route::post('bank-statement-lines/{bankStatementLine}/match-invoice', [BankAutoReconciliationController::class, 'matchToInvoice'])->name('bank-statement-lines.match-invoice');
                Route::post('bank-statement-lines/{bankStatementLine}/match-bill', [BankAutoReconciliationController::class, 'matchToBill'])->name('bank-statement-lines.match-bill');
                Route::get('bank-statement-lines/{bankStatementLine}/suggestions', [BankAutoReconciliationController::class, 'suggestions'])->name('bank-statement-lines.suggestions');

                // Bank Categorization Rules
                Route::prefix('bank-categorization-rules')->name('bank-categorization-rules.')->group(function (): void {
                    Route::get('/', [BankCategorizationController::class, 'rules'])->name('index');
                    Route::post('/', [BankCategorizationController::class, 'createRule'])->name('store');
                    Route::delete('{bankCategorizationRule}', [BankCategorizationController::class, 'deleteRule'])->name('destroy');
                });

                // Bank Statement Line actions
                Route::post('bank-statement-lines/{bankStatementLine}/apply-suggestion', [BankCategorizationController::class, 'applySuggestion'])->name('bank-statement-lines.apply-suggestion');
                Route::post('bank-statement-lines/{bankStatementLine}/learn', [BankCategorizationController::class, 'learn'])->name('bank-statement-lines.learn');

                // Bank Connections (Egyptian Bank API Integration)
                Route::prefix('bank-connections')->name('bank-connections.')->group(function (): void {
                    Route::get('dashboard', [BankConnectionController::class, 'dashboard'])->name('dashboard');
                    Route::get('supported-formats', [BankConnectionController::class, 'supportedFormats'])->name('supported-formats');
                    Route::post('generate-instruction', [BankConnectionController::class, 'generateInstruction'])->name('generate-instruction');
                    Route::get('/', [BankConnectionController::class, 'index'])->name('index');
                    Route::post('/', [BankConnectionController::class, 'store'])->name('store');
                    Route::get('{bankConnection}', [BankConnectionController::class, 'show'])->name('show');
                    Route::put('{bankConnection}', [BankConnectionController::class, 'update'])->name('update');
                    Route::delete('{bankConnection}', [BankConnectionController::class, 'destroy'])->name('destroy');
                    Route::post('{bankConnection}/sync-balance', [BankConnectionController::class, 'syncBalance'])->name('sync-balance');
                    Route::post('{bankConnection}/import-statement', [BankConnectionController::class, 'importStatement'])->name('import-statement');
                });

                // FX Revaluations
                Route::prefix('fx-revaluations')->name('fx-revaluations.')->group(function (): void {
                    Route::get('/', [FxRevaluationController::class, 'index'])->name('index');
                    Route::post('/', [FxRevaluationController::class, 'calculate'])->name('calculate');
                    Route::get('{fxRevaluation}', [FxRevaluationController::class, 'show'])->name('show');
                    Route::post('{fxRevaluation}/post', [FxRevaluationController::class, 'post'])->name('post');
                    Route::post('{fxRevaluation}/reverse', [FxRevaluationController::class, 'reverse'])->name('reverse');
                });
            });

            // ── E-Commerce Integration ──
            Route::middleware('permission:manage_integrations')->group(function (): void {
                Route::prefix('ecommerce')->name('ecommerce.')->group(function (): void {
                    Route::get('dashboard', [ECommerceController::class, 'dashboard'])->name('dashboard');
                    Route::post('bulk-convert', [ECommerceController::class, 'bulkConvert'])->name('bulk-convert');

                    Route::prefix('channels')->name('channels.')->group(function (): void {
                        Route::get('/', [ECommerceController::class, 'index'])->name('index');
                        Route::post('/', [ECommerceController::class, 'store'])->name('store');
                        Route::get('{ecommerceChannel}', [ECommerceController::class, 'show'])->name('show');
                        Route::put('{ecommerceChannel}', [ECommerceController::class, 'update'])->name('update');
                        Route::delete('{ecommerceChannel}', [ECommerceController::class, 'destroy'])->name('destroy');
                        Route::post('{ecommerceChannel}/sync', [ECommerceController::class, 'syncOrders'])->name('sync');
                    });

                    Route::post('orders/{ecommerceOrder}/convert', [ECommerceController::class, 'convertToInvoice'])->name('orders.convert');
                });
            });

            // ── Journal Entries (admin + accountant for CRUD, admin only for post) ──
            Route::middleware(['feature:accounting', 'permission:manage_journal_entries'])->group(function (): void {
                Route::apiResource('journal-entries', JournalEntryController::class);
                Route::post('journal-entries/{journalEntry}/reverse', [JournalEntryController::class, 'reverse'])->name('journal-entries.reverse');
                Route::post('import/opening-balances', [CsvImportController::class, 'importOpeningBalances'])->name('import.opening-balances');
            });
            Route::middleware(['feature:accounting', 'permission:post_journal_entries'])->group(function (): void {
                Route::post('journal-entries/{journalEntry}/post', [JournalEntryController::class, 'post'])->name('journal-entries.post');
                Route::apiResource('fiscal-years', FiscalYearController::class)->only(['index', 'store', 'show']);
                Route::post('fiscal-periods/{fiscalPeriod}/close', [FiscalPeriodController::class, 'close'])->name('fiscal-periods.close');
                Route::post('fiscal-periods/{fiscalPeriod}/reopen', [FiscalPeriodController::class, 'reopen'])->name('fiscal-periods.reopen');
            });

            // ── Recurring Journal Entries ──
            Route::middleware(['feature:accounting', 'permission:manage_accounts'])->group(function (): void {
                Route::apiResource('recurring-journal-entries', RecurringJournalEntryController::class);
                Route::post('recurring-journal-entries/{recurringJournalEntry}/toggle', [RecurringJournalEntryController::class, 'toggle'])->name('recurring-journal-entries.toggle');
            });

            // ── Invoices (admin + accountant) ──
            Route::middleware(['feature:invoicing', 'permission:manage_invoices'])->group(function (): void {
                Route::apiResource('invoices', InvoiceController::class);
                Route::post('invoices/pre-check', function (Request $request) {
                    $request->validate([
                        'client_id' => 'required|integer',
                        'total' => 'required|numeric|min:0',
                        'date' => 'nullable|date',
                    ]);
                    $warnings = InvoiceGuardService::check(
                        tenantId: app('tenant.id'),
                        clientId: (int) $request->input('client_id'),
                        newInvoiceTotal: (float) $request->input('total'),
                        date: $request->input('date'),
                    );

                    return response()->json(['data' => ['warnings' => $warnings, 'can_proceed' => empty(array_filter($warnings, fn ($w) => $w['severity'] === 'error'))]]);
                })->name('invoices.pre-check');
                Route::post('invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])->name('invoices.cancel');
                Route::post('invoices/{invoice}/post-to-gl', [InvoiceController::class, 'postToGL'])->name('invoices.post-to-gl');
                Route::post('invoices/{invoice}/credit-note', [InvoiceController::class, 'creditNote'])->name('invoices.credit-note');
                Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');
            });
            Route::middleware(['feature:invoicing', 'permission:send_invoices'])->group(function (): void {
                Route::post('invoices/{invoice}/send', [InvoiceController::class, 'send'])->name('invoices.send');
            });

            // ── Recurring Invoices ──
            Route::middleware(['feature:invoicing', 'permission:manage_invoices'])->group(function (): void {
                Route::apiResource('recurring-invoices', RecurringInvoiceController::class);
            });

            // ── AR Collection ──
            Route::middleware(['feature:collections', 'permission:manage_collections'])->prefix('collections')->name('collections.')->group(function (): void {
                Route::get('overview', [CollectionController::class, 'overview'])->name('overview');
                Route::get('actions', [CollectionController::class, 'listActions'])->name('actions.index');
                Route::post('actions', [CollectionController::class, 'logAction'])->name('actions.store');
                Route::get('clients/{client}', [CollectionController::class, 'clientSummary'])->name('clients.summary');
                Route::get('reports/effectiveness', [CollectionController::class, 'effectiveness'])->name('reports.effectiveness');
            });
            Route::post('invoices/{invoice}/write-off', [CollectionController::class, 'writeOff'])->name('invoices.write-off')->middleware(['feature:collections', 'permission:manage_collections']);
            Route::post('invoices/{invoice}/escalate', [CollectionController::class, 'escalate'])->name('invoices.escalate')->middleware(['feature:collections', 'permission:manage_collections']);

            // ── Data Import ──
            Route::middleware(['feature:clients', 'permission:manage_clients'])->prefix('import')->name('import.')->group(function (): void {
                Route::get('/', [ImportController::class, 'index'])->name('index');
                Route::post('/', [ImportController::class, 'store'])->name('store');
                Route::get('template/{type}', [ImportController::class, 'template'])->name('template');
                Route::get('{importJob}', [ImportController::class, 'show'])->name('show');
            });

            // ── Messaging (Beon.chat — WhatsApp/SMS) ──
            Route::middleware(['feature:clients', 'permission:manage_clients'])->prefix('messaging')->name('messaging.')->group(function (): void {
                Route::post('whatsapp', [MessagingController::class, 'sendWhatsApp'])->middleware('throttle:10,1')->name('whatsapp');
                Route::post('sms', [MessagingController::class, 'sendSms'])->middleware('throttle:10,1')->name('sms');
                Route::get('templates', [MessagingController::class, 'templates'])->name('templates');
                Route::get('conversations', [MessagingController::class, 'conversations'])->name('conversations');
                Route::get('conversations/{conversationId}', [MessagingController::class, 'conversationMessages'])->name('conversations.messages');
                Route::post('conversations/{conversationId}/reply', [MessagingController::class, 'reply'])->name('conversations.reply');
            });

            // ── Currency & Exchange Rates ──
            Route::prefix('currencies')->name('currencies.')->group(function (): void {
                Route::get('/', [CurrencyController::class, 'index'])->name('index');
                Route::post('convert', [CurrencyController::class, 'convert'])->name('convert');
                Route::get('rate-history', [CurrencyController::class, 'rateHistory'])->name('rate-history');
            });

            // ── Payments (admin + accountant) ──
            Route::middleware(['feature:invoicing', 'permission:manage_payments'])->group(function (): void {
                Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
                Route::post('payments', [PaymentController::class, 'store'])->middleware('throttle:10,1')->name('payments.store');
                Route::delete('payments/{payment}', [PaymentController::class, 'destroy'])->name('payments.destroy');
            });

            // ── Accounts Payable ──
            Route::middleware(['feature:bills_vendors', 'permission:manage_vendors'])->group(function (): void {
                Route::apiResource('vendors', VendorController::class);
                Route::get('vendors/{vendor}/statement', [VendorController::class, 'statement'])->name('vendors.statement');
                Route::get('vendors/reports/aging', [VendorController::class, 'aging'])->name('vendors.aging');
            });

            Route::middleware(['feature:bills_vendors', 'permission:manage_bills'])->group(function (): void {
                Route::apiResource('bills', BillController::class);
                Route::post('bills/{bill}/approve', [BillController::class, 'approve'])->name('bills.approve');
                Route::post('bills/{bill}/cancel', [BillController::class, 'cancel'])->name('bills.cancel');
                Route::get('bills/{bill}/payments', [BillPaymentController::class, 'index'])->name('bills.payments.index');
                Route::post('bills/{bill}/payments', [BillPaymentController::class, 'store'])->name('bills.payments.store');
                Route::delete('bill-payments/{billPayment}/void', [BillPaymentController::class, 'void'])->name('bill-payments.void');
            });

            // ── Fixed Assets ──
            Route::middleware(['feature:fixed_assets', 'permission:manage_fixed_assets'])->group(function (): void {
                Route::apiResource('asset-categories', AssetCategoryController::class);
                Route::apiResource('fixed-assets', FixedAssetController::class);
                Route::get('fixed-assets/{fixedAsset}/depreciation-schedule', [FixedAssetController::class, 'schedule'])->name('fixed-assets.schedule');
                Route::post('fixed-assets/depreciate', [FixedAssetController::class, 'depreciate'])->name('fixed-assets.depreciate');
                Route::get('fixed-assets/reports/register', [FixedAssetController::class, 'register'])->name('fixed-assets.register');
                Route::get('fixed-assets/reports/roll-forward', [FixedAssetController::class, 'rollForward'])->name('fixed-assets.roll-forward');
                Route::get('fixed-assets/{fixedAsset}/disposals', [AssetDisposalController::class, 'index'])->name('fixed-assets.disposals.index');
                Route::post('fixed-assets/{fixedAsset}/dispose', [AssetDisposalController::class, 'store'])->name('fixed-assets.dispose');
            });

            // ── Expenses ──
            Route::middleware(['feature:expenses', 'permission:manage_expenses'])->group(function (): void {
                Route::apiResource('expense-categories', ExpenseCategoryController::class);
                Route::apiResource('expenses', ExpenseController::class);
                Route::post('expenses/{expense}/submit', [ExpenseController::class, 'submit'])->name('expenses.submit');
                Route::post('expenses/{expense}/approve', [ExpenseController::class, 'approve'])->name('expenses.approve');
                Route::post('expenses/{expense}/reject', [ExpenseController::class, 'reject'])->name('expenses.reject');
                Route::post('expenses/{expense}/reimburse', [ExpenseController::class, 'reimburse'])->name('expenses.reimburse');
                Route::post('expenses/bulk-submit', [ExpenseController::class, 'bulkSubmit'])->name('expenses.bulk-submit');
                Route::get('expenses/reports/summary', [ExpenseController::class, 'summary'])->name('expenses.summary');
                Route::apiResource('expense-reports', ExpenseReportController::class)->only(['index', 'store', 'show']);
                Route::post('expense-reports/{expenseReport}/expenses', [ExpenseReportController::class, 'addExpenses'])->name('expense-reports.add-expenses');
                Route::post('expense-reports/{expenseReport}/submit', [ExpenseReportController::class, 'submit'])->name('expense-reports.submit');
                Route::post('expense-reports/{expenseReport}/approve', [ExpenseReportController::class, 'approve'])->name('expense-reports.approve');
                Route::post('expense-reports/{expenseReport}/reject', [ExpenseReportController::class, 'reject'])->name('expense-reports.reject');
            });

            // ── Cost Centers ──
            Route::middleware(['feature:cost_centers', 'permission:manage_cost_centers'])->group(function (): void {
                Route::apiResource('cost-centers', CostCenterController::class);
                Route::get('cost-centers/{costCenter}/pnl', [CostCenterController::class, 'profitAndLoss'])->name('cost-centers.pnl');
                Route::get('cost-centers/reports/cost-analysis', [CostCenterController::class, 'costAnalysis'])->name('cost-centers.cost-analysis');
                Route::get('cost-centers/reports/allocation', [CostCenterController::class, 'allocationReport'])->name('cost-centers.allocation');
            });

            // ── Inventory Management ──
            Route::middleware(['feature:inventory', 'permission:manage_inventory'])->group(function (): void {
                // Product Categories
                Route::get('product-categories', [InventoryController::class, 'categoryIndex'])->name('product-categories.index');
                Route::post('product-categories', [InventoryController::class, 'categoryStore'])->name('product-categories.store');
                Route::put('product-categories/{productCategory}', [InventoryController::class, 'categoryUpdate'])->name('product-categories.update');
                Route::delete('product-categories/{productCategory}', [InventoryController::class, 'categoryDestroy'])->name('product-categories.destroy');

                // Products
                Route::apiResource('products', InventoryController::class);

                // Inventory Operations
                Route::post('inventory/movements', [InventoryController::class, 'recordMovement'])->name('inventory.movements.store');
                Route::get('inventory/stock-report', [InventoryController::class, 'stockReport'])->name('inventory.stock-report');
                Route::get('inventory/low-stock', [InventoryController::class, 'lowStockAlert'])->name('inventory.low-stock');
                Route::get('inventory/valuation', [InventoryController::class, 'valuation'])->name('inventory.valuation');
                Route::get('inventory/turnover', [InventoryController::class, 'turnover'])->name('inventory.turnover');
                Route::get('products/{product}/movements', [InventoryController::class, 'movements'])->name('products.movements');
            });

            // ── Tax Management ──
            Route::middleware(['feature:tax', 'permission:manage_tax'])->group(function (): void {
                // WHT Certificates
                Route::get('wht-certificates', [WhtCertificateController::class, 'index'])->name('wht-certificates.index');
                Route::post('wht-certificates/generate', [WhtCertificateController::class, 'generate'])->name('wht-certificates.generate');
                Route::get('wht-certificates/{whtCertificate}', [WhtCertificateController::class, 'show'])->name('wht-certificates.show');
                Route::post('wht-certificates/{whtCertificate}/issue', [WhtCertificateController::class, 'issue'])->name('wht-certificates.issue');
                Route::post('wht-certificates/{whtCertificate}/submit', [WhtCertificateController::class, 'submit'])->name('wht-certificates.submit');

                // Tax Returns
                Route::get('tax-returns', [TaxReturnController::class, 'index'])->name('tax-returns.index');
                Route::get('tax-returns/{taxReturn}', [TaxReturnController::class, 'show'])->name('tax-returns.show');
                Route::post('tax-returns/corporate', [TaxReturnController::class, 'calculateCorporateTax'])->name('tax-returns.corporate');
                Route::post('tax-returns/vat', [TaxReturnController::class, 'calculateVatReturn'])->name('tax-returns.vat');
                Route::post('tax-returns/{taxReturn}/file', [TaxReturnController::class, 'file'])->name('tax-returns.file');
                Route::post('tax-returns/{taxReturn}/payment', [TaxReturnController::class, 'recordPayment'])->name('tax-returns.payment');

                // Tax Adjustments
                Route::get('tax-adjustments/{fiscalYear}', [TaxReturnController::class, 'adjustments'])->name('tax-adjustments.index');
                Route::post('tax-adjustments', [TaxReturnController::class, 'storeAdjustment'])->name('tax-adjustments.store');
                Route::delete('tax-adjustments/{taxAdjustment}', [TaxReturnController::class, 'destroyAdjustment'])->name('tax-adjustments.destroy');
            });

            // ── Invoice & Landing Page Settings (admin only) ──
            Route::middleware('permission:manage_settings')->group(function (): void {
                Route::get('invoice-settings', [InvoiceSettingsController::class, 'show'])->name('invoice-settings.show');
                Route::put('invoice-settings', [InvoiceSettingsController::class, 'update'])->name('invoice-settings.update');

                // Aging Reminders
                Route::prefix('aging-reminders')->name('aging-reminders.')->group(function (): void {
                    Route::get('settings', [AgingReminderController::class, 'settings'])->name('settings');
                    Route::put('settings', [AgingReminderController::class, 'updateSettings'])->name('settings.update');
                    Route::get('history', [AgingReminderController::class, 'history'])->name('history');
                    Route::get('invoices/{invoiceId}/history', [AgingReminderController::class, 'invoiceHistory'])->name('invoices.history');
                    Route::post('trigger', [AgingReminderController::class, 'trigger'])->name('trigger');
                });
            });
            Route::middleware('permission:manage_landing_page')->group(function (): void {
                Route::get('landing-page-settings', [LandingPageSettingsController::class, 'show'])->name('landing-page-settings.show');
                Route::put('landing-page-settings', [LandingPageSettingsController::class, 'update'])->name('landing-page-settings.update');
            });

            // ── Documents (all tenant roles) ──
            Route::middleware(['feature:documents', 'permission:manage_documents'])->group(function (): void {
                Route::get('documents/quota', [DocumentController::class, 'quota'])->name('documents.quota');
                Route::post('documents/bulk', [DocumentController::class, 'bulkStore'])->name('documents.bulk');
                Route::apiResource('documents', DocumentController::class);
                Route::get('documents/{document}/download', [DocumentController::class, 'download'])->name('documents.download');
                Route::post('documents/{document}/archive', [DocumentController::class, 'archive'])->name('documents.archive');
                Route::post('documents/{document}/unarchive', [DocumentController::class, 'unarchive'])->name('documents.unarchive');
            });

            // ── Onboarding (admin only) ──
            Route::middleware('permission:manage_onboarding')->prefix('onboarding')->name('onboarding.')->group(function (): void {
                Route::get('progress', [OnboardingController::class, 'progress'])->name('progress');
                Route::post('complete-step', [OnboardingController::class, 'completeStep'])->name('complete-step');
                Route::post('skip', [OnboardingController::class, 'skip'])->name('skip');
                Route::post('setup-coa', [OnboardingController::class, 'setupCoa'])->name('setup-coa');
                Route::post('setup-fiscal-year', [OnboardingController::class, 'setupFiscalYear'])->name('setup-fiscal-year');
                Route::post('load-sample-data', [OnboardingController::class, 'loadSampleData'])->name('load-sample-data');
                Route::post('invite-team-member', [OnboardingController::class, 'inviteTeamMember'])->name('invite-team-member');
            });

            // ── Onboarding Wizard (all authenticated tenants — no special permission) ──
            Route::prefix('onboarding-wizard')->name('onboarding-wizard.')->group(function (): void {
                Route::get('progress', [OnboardingWizardController::class, 'progress'])->name('progress');
                Route::get('templates', [OnboardingWizardController::class, 'templates'])->name('templates');
                Route::post('select-template', [OnboardingWizardController::class, 'selectTemplate'])->name('select-template');
                Route::post('import-balances', [OnboardingWizardController::class, 'importBalances'])->name('import-balances');
                Route::post('complete-step', [OnboardingWizardController::class, 'completeStep'])->name('complete-step');
                Route::post('skip-step', [OnboardingWizardController::class, 'skipStep'])->name('skip-step');
            });

            // ── Team (admin only) ──
            Route::middleware('permission:manage_team')->prefix('team')->name('team.')->group(function (): void {
                Route::get('/', [TeamController::class, 'index'])->name('index');
                Route::post('invite', [TeamController::class, 'invite'])->name('invite');
                Route::put('{user}', [TeamController::class, 'update'])->name('update');
                Route::patch('{user}/toggle-active', [TeamController::class, 'toggleActive'])->name('toggle-active');
                Route::delete('{user}', [TeamController::class, 'destroy'])->name('destroy');
                Route::put('{user}/role', [TeamController::class, 'assignRole'])->name('assign-role');
            });

            // ── Timesheets (admin + accountant) ──
            Route::middleware(['feature:timesheets', 'permission:manage_timesheets'])->group(function (): void {
                Route::get('timesheets/summary', [TimesheetController::class, 'summary'])->name('timesheets.summary');
                Route::post('timesheets/bulk-submit', [TimesheetController::class, 'bulkSubmit'])->name('timesheets.bulk-submit');
                Route::apiResource('timesheets', TimesheetController::class);
                Route::post('timesheets/{timesheet}/submit', [TimesheetController::class, 'submit'])->name('timesheets.submit');
                Route::post('timesheets/{timesheet}/reject', [TimesheetController::class, 'reject'])->name('timesheets.reject');
                Route::prefix('timers')->name('timers.')->group(function (): void {
                    Route::post('start', [TimerController::class, 'start'])->name('start');
                    Route::get('current', [TimerController::class, 'current'])->name('current');
                    Route::post('{timer}/stop', [TimerController::class, 'stop'])->name('stop');
                    Route::delete('{timer}', [TimerController::class, 'discard'])->name('discard');
                });
                Route::prefix('time-billing')->name('time-billing.')->group(function (): void {
                    Route::get('preview', [TimeBillingController::class, 'preview'])->name('preview');
                    Route::post('generate', [TimeBillingController::class, 'generate'])->name('generate');
                });
            });
            Route::middleware(['feature:timesheets', 'permission:approve_timesheets'])->group(function (): void {
                Route::post('timesheets/bulk-approve', [TimesheetController::class, 'bulkApprove'])->name('timesheets.bulk-approve');
                Route::post('timesheets/{timesheet}/approve', [TimesheetController::class, 'approve'])->name('timesheets.approve');
            });

            // ── Engagements & Working Papers ──
            Route::middleware('permission:manage_engagements')->group(function (): void {
                Route::get('engagements/dashboard', [EngagementController::class, 'dashboard'])->name('engagements.dashboard');
                Route::apiResource('engagements', EngagementController::class);
                Route::get('engagements/{engagement}/time-allocation', [EngagementController::class, 'timeAllocation'])->name('engagements.time-allocation');
                Route::post('engagements/{engagement}/deliverables', [EngagementController::class, 'addDeliverable'])->name('engagements.deliverables.store');
                Route::post('engagements/{engagement}/deliverables/{deliverable}/complete', [EngagementController::class, 'completeDeliverable'])->name('engagements.deliverables.complete');

                Route::get('engagements/{engagement}/working-papers', [WorkingPaperController::class, 'index'])->name('engagements.working-papers.index');
                Route::post('engagements/{engagement}/working-papers', [WorkingPaperController::class, 'store'])->name('engagements.working-papers.store');
                Route::put('working-papers/{workingPaper}', [WorkingPaperController::class, 'update'])->name('working-papers.update');
                Route::post('working-papers/{workingPaper}/review', [WorkingPaperController::class, 'review'])->name('working-papers.review');
            });

            // ── Employees (admin only) ──
            Route::middleware(['feature:payroll', 'permission:manage_employees'])->prefix('employees')->name('employees.')->group(function (): void {
                Route::get('/', [PayrollController::class, 'listEmployees'])->name('index');
                Route::post('/', [PayrollController::class, 'storeEmployee'])->name('store');
                Route::get('{employee}', [PayrollController::class, 'showEmployee'])->name('show');
                Route::put('{employee}', [PayrollController::class, 'updateEmployee'])->name('update');
                Route::delete('{employee}', [PayrollController::class, 'destroyEmployee'])->name('destroy');
            });

            // ── Payroll (admin only) ──
            Route::middleware(['feature:payroll', 'permission:manage_payroll'])->prefix('payroll')->name('payroll.')->group(function (): void {
                Route::get('/', [PayrollController::class, 'index'])->name('index');
                Route::post('/', [PayrollController::class, 'store'])->name('store');
                Route::get('{payrollRun}', [PayrollController::class, 'show'])->name('show');
                Route::delete('{payrollRun}', [PayrollController::class, 'destroy'])->name('destroy');
                Route::post('{payrollRun}/calculate', [PayrollController::class, 'calculate'])->name('calculate');
                Route::post('{payrollRun}/approve', [PayrollController::class, 'approve'])->name('approve');
                Route::post('{payrollRun}/mark-paid', [PayrollController::class, 'markPaid'])->name('mark-paid');
                Route::get('{payrollRun}/items', [PayrollController::class, 'items'])->name('items');
                Route::get('{payrollRun}/items/{payrollItem}/payslip', [PayslipController::class, 'download'])->name('payslip');
            });

            // ── Payroll Extensions ──
            Route::middleware(['feature:payroll', 'permission:manage_payroll'])->group(function (): void {
                // Salary Components
                Route::apiResource('salary-components', SalaryComponentController::class)->except(['show']);
                Route::post('employees/{employee}/salary-components', [SalaryComponentController::class, 'assign'])->name('employees.salary-components.assign');
                Route::get('employees/{employee}/salary-components', [SalaryComponentController::class, 'employeeComponents'])->name('employees.salary-components.index');

                // Loans
                Route::apiResource('loans', LoanController::class)->only(['index', 'store']);
                Route::post('loans/{loan}/installment', [LoanController::class, 'recordInstallment'])->name('loans.installment');
                Route::post('loans/{loan}/cancel', [LoanController::class, 'cancel'])->name('loans.cancel');

                // Leave Management
                Route::get('leave-types', [LeaveController::class, 'types'])->name('leave-types.index');
                Route::post('leave-types', [LeaveController::class, 'createType'])->name('leave-types.store');
                Route::get('leave-requests', [LeaveController::class, 'requests'])->name('leave-requests.index');
                Route::post('leave-requests', [LeaveController::class, 'request'])->name('leave-requests.store');
                Route::post('leave-requests/{leaveRequest}/approve', [LeaveController::class, 'approve'])->name('leave-requests.approve');
                Route::post('leave-requests/{leaveRequest}/reject', [LeaveController::class, 'reject'])->name('leave-requests.reject');
                Route::post('leave-requests/{leaveRequest}/cancel', [LeaveController::class, 'cancel'])->name('leave-requests.cancel');
                Route::get('employees/{employee}/leave-balance', [LeaveController::class, 'balance'])->name('employees.leave-balance');

                // Attendance
                Route::get('attendance', [AttendanceController::class, 'index'])->name('attendance.index');
                Route::post('attendance', [AttendanceController::class, 'store'])->name('attendance.store');
                Route::post('attendance/bulk', [AttendanceController::class, 'bulkStore'])->name('attendance.bulk');
                Route::get('employees/{employee}/attendance-summary', [AttendanceController::class, 'summary'])->name('employees.attendance-summary');
            });

            // ── Labor Law Calculations ──
            Route::middleware(['feature:payroll', 'permission:manage_payroll'])->prefix('labor-law')->name('labor-law.')->group(function (): void {
                Route::post('overtime', [LaborLawController::class, 'calculateOvertime']);
                Route::post('end-of-service', [LaborLawController::class, 'calculateEndOfService']);
                Route::get('leave-entitlement/{employee}', [LaborLawController::class, 'leaveEntitlement']);
                Route::post('validate-wage', [LaborLawController::class, 'validateWage']);
                Route::post('social-insurance', [LaborLawController::class, 'socialInsurance']);
            });

            // ── Social Insurance (SISA) ──
            Route::middleware(['feature:payroll', 'permission:manage_payroll'])->prefix('social-insurance')->name('social-insurance.')->group(function (): void {
                Route::post('calculate', [SocialInsuranceController::class, 'calculate'])->name('calculate');
                Route::get('monthly-report', [SocialInsuranceController::class, 'monthlyReport'])->name('monthly-report');
                Route::post('register', [SocialInsuranceController::class, 'register'])->name('register');
                Route::get('rates', [SocialInsuranceController::class, 'rates'])->name('rates');
            });

            // ── ETA E-Invoicing (admin + accountant) ──
            Route::middleware(['feature:e_invoice', 'permission:manage_eta'])->prefix('eta')->name('eta.')->group(function (): void {
                Route::get('settings', [EtaController::class, 'showSettings'])->name('settings.show');
                Route::put('settings', [EtaController::class, 'updateSettings'])->name('settings.update');
                Route::get('documents', [EtaController::class, 'indexDocuments'])->name('documents.index');
                Route::post('documents/{invoice}/prepare', [EtaController::class, 'prepare'])->name('documents.prepare');
                Route::post('documents/{invoice}/submit', [EtaController::class, 'submit'])->name('documents.submit');
                Route::get('documents/{invoice}', [EtaController::class, 'showDocument'])->name('documents.show');
                Route::post('documents/{invoice}/cancel', [EtaController::class, 'cancelDocument'])->name('documents.cancel');
                Route::post('documents/{invoice}/check-status', [EtaController::class, 'checkStatus'])->name('documents.check-status');
                Route::post('reconcile', [EtaController::class, 'reconcile'])->name('reconcile');

                // Compliance Dashboard
                Route::get('compliance-dashboard', [EtaController::class, 'complianceDashboard'])->name('compliance-dashboard');
                Route::post('bulk-retry', [EtaController::class, 'bulkRetry'])->name('bulk-retry');
                Route::post('bulk-status-check', [EtaController::class, 'bulkStatusCheck'])->name('bulk-status-check');

                Route::post('item-codes/bulk-assign', [EtaItemCodeController::class, 'bulkAssign'])->name('eta.item-codes.bulk-assign');
                Route::post('item-codes/bulk-import', [EtaItemCodeController::class, 'bulkImport'])->name('eta.item-codes.bulk-import');
                Route::post('item-codes/auto-assign', [EtaItemCodeController::class, 'autoAssign'])->name('eta.item-codes.auto-assign');
                Route::get('item-codes/usage-report', [EtaItemCodeController::class, 'usageReport'])->name('eta.item-codes.usage-report');
                Route::get('item-codes/unmapped-lines', [EtaItemCodeController::class, 'unmappedLines'])->name('eta.item-codes.unmapped-lines');
                Route::get('item-codes/suggest', [EtaItemCodeController::class, 'suggestCode'])->name('eta.item-codes.suggest');
                Route::get('item-codes/mappings', [EtaItemCodeController::class, 'mappings'])->name('eta.item-codes.mappings');
                Route::post('item-codes/mappings', [EtaItemCodeController::class, 'createMapping'])->name('eta.item-codes.mappings.store');
                Route::delete('item-codes/mappings/{mapping}', [EtaItemCodeController::class, 'deleteMapping'])->name('eta.item-codes.mappings.destroy');

                Route::apiResource('item-codes', EtaItemCodeController::class);
            });

            // ── Reports (all tenant roles) ──
            Route::middleware(['permission:view_reports', 'throttle:reports'])->prefix('reports')->name('reports.')->group(function (): void {
                Route::get('trial-balance', [ReportController::class, 'trialBalance'])
                    ->name('trial-balance');
                Route::get('accounts/{account}/ledger', [ReportController::class, 'accountLedger'])
                    ->name('account-ledger');
                Route::get('clients/{client}/statement', [ReportController::class, 'clientStatement'])
                    ->name('client-statement');
                Route::get('aging', [ReportController::class, 'agingReport'])
                    ->name('aging');

                // Financial Statements
                Route::get('income-statement', [ReportController::class, 'incomeStatement'])
                    ->name('income-statement');
                Route::get('balance-sheet', [ReportController::class, 'balanceSheet'])
                    ->name('balance-sheet');
                Route::get('cash-flow', [ReportController::class, 'cashFlow'])
                    ->name('cash-flow');

                // Comparative Reports
                Route::get('comparative/income-statement', [ReportController::class, 'comparativeIncomeStatement'])
                    ->name('comparative-income-statement');
                Route::get('comparative/balance-sheet', [ReportController::class, 'comparativeBalanceSheet'])
                    ->name('comparative-balance-sheet');

                // PDF Exports
                Route::get('income-statement/pdf', [ReportController::class, 'incomeStatementPdf'])
                    ->name('income-statement-pdf');
                Route::get('balance-sheet/pdf', [ReportController::class, 'balanceSheetPdf'])
                    ->name('balance-sheet-pdf');
                Route::get('cash-flow/pdf', [ReportController::class, 'cashFlowPdf'])
                    ->name('cash-flow-pdf');
                Route::get('trial-balance/pdf', [ReportController::class, 'trialBalancePdf'])
                    ->name('trial-balance-pdf');

                // Async PDF Generation
                Route::post('pdf/async', [ReportController::class, 'generatePdfAsync'])
                    ->name('pdf-async');

                // Tax Reports
                Route::get('vat-return', [ReportController::class, 'vatReturn'])
                    ->name('vat-return');
                Route::get('wht', [ReportController::class, 'whtReport'])
                    ->name('wht');
                Route::get('vat-return/pdf', [ReportController::class, 'vatReturnPdf'])
                    ->name('vat-return-pdf');
                Route::get('wht/pdf', [ReportController::class, 'whtReportPdf'])
                    ->name('wht-pdf');
            });

            // ── Executive Dashboard ──
            Route::middleware(['feature:reports', 'permission:view_reports'])->prefix('dashboard')->name('dashboard.')->group(function (): void {
                Route::get('overview', [ExecutiveDashboardController::class, 'overview'])->name('overview');
                Route::get('revenue', [ExecutiveDashboardController::class, 'revenueAnalysis'])->name('revenue');
                Route::get('cash-flow', [ExecutiveDashboardController::class, 'cashFlow'])->name('cash-flow');
                Route::get('profitability', [ExecutiveDashboardController::class, 'profitability'])->name('profitability');
                Route::get('kpis', [ExecutiveDashboardController::class, 'kpis'])->name('kpis');
                Route::get('comparison', [ExecutiveDashboardController::class, 'comparison'])->name('comparison');
            });

            // CSV/JSONL Exports (streaming, memory-efficient)
            // ── Budget vs Actuals ──
            Route::middleware(['feature:budgeting', 'permission:manage_accounts'])->prefix('budgets')->name('budgets.')->group(function (): void {
                Route::get('/', [BudgetController::class, 'index'])->name('index');
                Route::post('/', [BudgetController::class, 'store'])->name('store');
                Route::get('{budget}', [BudgetController::class, 'show'])->name('show');
                Route::put('{budget}', [BudgetController::class, 'update'])->name('update');
                Route::delete('{budget}', [BudgetController::class, 'destroy'])->name('destroy');
                Route::post('{budget}/lines', [BudgetController::class, 'setLines'])->name('lines');
                Route::post('{budget}/approve', [BudgetController::class, 'approve'])->name('approve');
                Route::get('{budget}/variance', [BudgetController::class, 'variance'])->name('variance');
            });

            // ── Custom Report Builder ──
            Route::middleware(['feature:custom_reports', 'permission:view_reports'])->prefix('custom-reports')->name('custom-reports.')->group(function (): void {
                Route::post('execute', [CustomReportController::class, 'execute'])->name('execute');
                Route::get('/', [CustomReportController::class, 'index'])->name('index');
                Route::post('/', [CustomReportController::class, 'store'])->name('store');
                Route::get('{savedReport}', [CustomReportController::class, 'show'])->name('show');
                Route::put('{savedReport}', [CustomReportController::class, 'update'])->name('update');
                Route::delete('{savedReport}', [CustomReportController::class, 'destroy'])->name('destroy');
                Route::get('{savedReport}/run', [CustomReportController::class, 'run'])->name('run');
            });

            // ── Scheduled Reports ──
            Route::middleware(['feature:reports', 'permission:manage_reports'])->prefix('scheduled-reports')->name('scheduled-reports.')->group(function (): void {
                Route::get('/', [ScheduledReportController::class, 'index'])->name('index');
                Route::post('/', [ScheduledReportController::class, 'store'])->name('store');
                Route::get('{scheduledReport}', [ScheduledReportController::class, 'show'])->name('show');
                Route::put('{scheduledReport}', [ScheduledReportController::class, 'update'])->name('update');
                Route::delete('{scheduledReport}', [ScheduledReportController::class, 'destroy'])->name('destroy');
                Route::post('{scheduledReport}/toggle', [ScheduledReportController::class, 'toggle'])->name('toggle');
                Route::post('{scheduledReport}/send-now', [ScheduledReportController::class, 'sendNow'])->name('send-now');
            });

            Route::middleware(['permission:view_reports', 'throttle:exports'])->prefix('export')->name('export.')->group(function (): void {
                Route::get('clients', [ExportController::class, 'clients'])->name('clients');
                Route::get('invoices', [ExportController::class, 'invoices'])->name('invoices');
                Route::get('journal-entries', [ExportController::class, 'journalEntries'])->name('journal-entries');
            });

            // ── Anomaly Detection ──
            Route::middleware(['feature:reports', 'permission:view_reports'])->prefix('anomalies')->name('anomalies.')->group(function (): void {
                Route::get('/', [AnomalyDetectionController::class, 'detectAll'])->name('detect-all');
                Route::get('duplicates', [AnomalyDetectionController::class, 'duplicates'])->name('duplicates');
                Route::get('unusual-amounts', [AnomalyDetectionController::class, 'unusualAmounts'])->name('unusual-amounts');
                Route::get('missing-sequences', [AnomalyDetectionController::class, 'missingSequences'])->name('missing-sequences');
                Route::get('weekend-entries', [AnomalyDetectionController::class, 'weekendEntries'])->name('weekend-entries');
            });

            // ── Approval Workflows ──
            Route::middleware('permission:manage_approvals')->group(function (): void {
                Route::apiResource('approval-workflows', ApprovalWorkflowController::class);

                Route::prefix('approvals')->name('approvals.')->group(function (): void {
                    Route::post('submit', [ApprovalController::class, 'submit'])->name('submit');
                    Route::post('{approvalRequest}/approve', [ApprovalController::class, 'approve'])->name('approve');
                    Route::post('{approvalRequest}/reject', [ApprovalController::class, 'reject'])->name('reject');
                    Route::get('pending', [ApprovalController::class, 'pending'])->name('pending');
                    Route::get('history', [ApprovalController::class, 'history'])->name('history');
                });
            });

            // ── Alert Rules (admin + accountant) ──
            Route::middleware('permission:manage_alerts')->prefix('alert-rules')->name('alert-rules.')->group(function (): void {
                Route::get('/', [AlertRuleController::class, 'index'])->name('index');
                Route::post('/', [AlertRuleController::class, 'store'])->name('store');
                Route::get('history', [AlertRuleController::class, 'history'])->name('history');
                Route::get('{alertRule}', [AlertRuleController::class, 'show'])->name('show');
                Route::put('{alertRule}', [AlertRuleController::class, 'update'])->name('update');
                Route::delete('{alertRule}', [AlertRuleController::class, 'destroy'])->name('destroy');
                Route::post('{alertRule}/toggle', [AlertRuleController::class, 'toggle'])->name('toggle');
            });
        });

        // ──────────────────────────────────
        // Webhook Endpoints (tenant-scoped, requires manage_settings permission)
        // ──────────────────────────────────
        Route::middleware(['tenant', 'active', 'permission:manage_settings'])
            ->prefix('webhooks')
            ->name('webhooks.')
            ->group(function (): void {
                Route::get('events', [WebhookEndpointController::class, 'events'])->name('events');
                Route::get('/', [WebhookEndpointController::class, 'index'])->name('index');
                Route::post('/', [WebhookEndpointController::class, 'store'])->name('store');
                Route::put('{webhookEndpoint}', [WebhookEndpointController::class, 'update'])->name('update');
                Route::delete('{webhookEndpoint}', [WebhookEndpointController::class, 'destroy'])->name('destroy');
                Route::get('{webhookEndpoint}/deliveries', [WebhookEndpointController::class, 'deliveries'])->name('deliveries');
            });

        // ──────────────────────────────────
        // Super Admin routes
        // ──────────────────────────────────
        Route::middleware(['super_admin', 'admin.ip', 'enforce.2fa'])->prefix('admin')->name('admin.')->group(function (): void {
            // ── DEPRECATED: use Filament /admin/plans — sunset 2026-07-17 ──
            Route::middleware('deprecated:/admin/plans,2026-07-17')->group(function (): void {
                Route::apiResource('plans', PlanController::class)->only(['store', 'update', 'destroy']);
            });

            // Dashboard & Revenue (not deprecated — no Filament equivalent)
            Route::get('dashboard', [AdminDashboardController::class, 'index'])->name('dashboard');
            Route::get('revenue/monthly', [AdminDashboardController::class, 'monthlyRevenue'])->name('revenue.monthly');
            Route::get('revenue/by-plan', [AdminDashboardController::class, 'revenueByPlan'])->name('revenue.by-plan');

            // ── DEPRECATED: use Filament /admin/tenants — sunset 2026-07-17 ──
            Route::middleware('deprecated:/admin/tenants,2026-07-17')->group(function (): void {
                Route::get('tenants', [AdminTenantController::class, 'index'])->name('tenants.index');
                Route::get('tenants/{tenant}', [AdminTenantController::class, 'show'])->name('tenants.show');
                Route::put('tenants/{tenant}', [AdminTenantController::class, 'update'])->name('tenants.update');
                Route::post('tenants/{tenant}/suspend', [AdminTenantController::class, 'suspend'])->middleware('throttle:10,1')->name('tenants.suspend');
                Route::post('tenants/{tenant}/activate', [AdminTenantController::class, 'activate'])->middleware('throttle:10,1')->name('tenants.activate');
                Route::post('tenants/{tenant}/cancel', [AdminTenantController::class, 'cancel'])->middleware('throttle:10,1')->name('tenants.cancel');
                Route::post('tenants/{tenant}/impersonate', [AdminTenantController::class, 'impersonate'])->middleware('throttle:5,1')->name('tenants.impersonate');
            });

            // ── DEPRECATED: use Filament /admin/subscriptions — sunset 2026-07-17 ──
            Route::middleware('deprecated:/admin/subscriptions,2026-07-17')->group(function (): void {
                Route::post('subscriptions/assign', [AdminSubscriptionController::class, 'assign'])->name('subscriptions.assign');
                Route::get('subscriptions', [AdminSubscriptionController::class, 'index'])->name('subscriptions.index');
                Route::get('subscriptions/{subscription}', [AdminSubscriptionController::class, 'show'])->name('subscriptions.show');
                Route::put('subscriptions/{subscription}', [AdminSubscriptionController::class, 'update'])->name('subscriptions.update');
                Route::post('subscriptions/payments/{payment}/refund', [AdminSubscriptionController::class, 'refund'])->name('subscriptions.refund');
            });

            // ── DEPRECATED: use Filament /admin/users — sunset 2026-07-17 ──
            Route::middleware('deprecated:/admin/users,2026-07-17')->group(function (): void {
                Route::get('users', [AdminUserController::class, 'index'])->name('users.index');
                Route::post('users/create-super-admin', [AdminUserController::class, 'createSuperAdmin'])->name('users.create-super-admin');
                Route::patch('users/{user}/toggle-active', [AdminUserController::class, 'toggleActive'])->name('users.toggle-active');
            });

            // Investors
            Route::apiResource('investors', AdminInvestorController::class);
            Route::get('investors/{investor}/shares', [AdminInvestorController::class, 'shares'])->name('investors.shares');
            Route::post('investors/{investor}/shares', [AdminInvestorController::class, 'setShare'])->name('investors.set-share');
            Route::delete('investors/{investor}/shares/{tenant}', [AdminInvestorController::class, 'removeShare'])->name('investors.remove-share');

            // Profit Distributions
            Route::get('distributions', [AdminProfitDistributionController::class, 'index'])->name('distributions.index');
            Route::post('distributions/calculate', [AdminProfitDistributionController::class, 'calculate'])->name('distributions.calculate');
            Route::get('distributions/{distribution}', [AdminProfitDistributionController::class, 'show'])->name('distributions.show');
            Route::post('distributions/{distribution}/approve', [AdminProfitDistributionController::class, 'approve'])->name('distributions.approve');
            Route::post('distributions/{distribution}/mark-paid', [AdminProfitDistributionController::class, 'markPaid'])->name('distributions.mark-paid');
            Route::delete('distributions/{distribution}', [AdminProfitDistributionController::class, 'destroy'])->name('distributions.destroy');
            Route::get('investors/{investor}/payslip', [AdminProfitDistributionController::class, 'payslip'])->name('investors.payslip');

            // ── DEPRECATED: use Filament /admin/audit-logs — sunset 2026-07-17 ──
            Route::middleware('deprecated:/admin/audit-logs,2026-07-17')->group(function (): void {
                // Activity Log
                Route::get('activity-log', [AdminActivityLogController::class, 'index'])->name('activity-log.index');
                Route::get('tenants/{tenantId}/activity', [AdminActivityLogController::class, 'forTenant'])->name('tenants.activity');

                // Audit Logs (Spatie Activity Log viewer)
                Route::get('audit-logs', [AdminAuditLogController::class, 'index'])->name('audit-logs.index');
                Route::get('audit-logs/stats', [AdminAuditLogController::class, 'stats'])->name('audit-logs.stats');
            });

            // Platform Settings
            Route::get('settings', [AdminPlatformSettingsController::class, 'index'])->name('settings.index');
            Route::put('settings', [AdminPlatformSettingsController::class, 'update'])->name('settings.update');

            // CMS Analytics
            Route::get('cms/analytics', [AdminCmsController::class, 'analytics'])->name('cms.analytics');

            // API Request Logs
            Route::get('api-logs', [AdminApiLogController::class, 'index'])->name('api-logs.index');
            Route::get('api-logs/stats', [AdminApiLogController::class, 'stats'])->name('api-logs.stats');

            // Usage Metering
            Route::get('usage/platform', [AdminUsageController::class, 'platformUsage'])->name('usage.platform');
            Route::get('usage/tenants/{tenantId}', [AdminUsageController::class, 'tenantUsage'])->name('usage.tenant');

            // System Metrics
            Route::get('metrics', [AdminMetricsController::class, 'index'])->name('metrics.index');
            Route::post('metrics/reset-circuit-breaker', [AdminMetricsController::class, 'resetCircuitBreaker'])->name('metrics.reset-circuit');

            // Integration Settings
            Route::prefix('integrations')->name('integrations.')->group(function (): void {
                Route::get('/', [AdminIntegrationController::class, 'index'])->name('index');
                Route::get('{integrationSetting}', [AdminIntegrationController::class, 'show'])->name('show');
                Route::post('/', [AdminIntegrationController::class, 'upsert'])->name('upsert');
                Route::post('{integrationSetting}/verify', [AdminIntegrationController::class, 'verify'])->name('verify');
                Route::post('{integrationSetting}/toggle', [AdminIntegrationController::class, 'toggle'])->name('toggle');
            });

            // Batch Operations
            Route::prefix('batch')->name('batch.')->group(function (): void {
                Route::post('tenants/update-status', [AdminBatchController::class, 'updateTenantStatus'])->middleware('throttle:10,1')->name('tenants.update-status');
                Route::post('users/toggle-active', [AdminBatchController::class, 'toggleUsersActive'])->middleware('throttle:10,1')->name('users.toggle-active');
                Route::post('users/delete', [AdminBatchController::class, 'deleteUsers'])->middleware('throttle:10,1')->name('users.delete');
                Route::post('contacts/update-status', [AdminBatchController::class, 'updateContactStatus'])->middleware('throttle:10,1')->name('contacts.update-status');
                Route::post('contacts/mark-read', [AdminBatchController::class, 'markContactsRead'])->middleware('throttle:10,1')->name('contacts.mark-read');
                Route::post('contacts/delete', [AdminBatchController::class, 'deleteContacts'])->middleware('throttle:10,1')->name('contacts.delete');
                Route::post('blog/delete', [AdminBatchController::class, 'deleteBlogPosts'])->middleware('throttle:10,1')->name('blog.delete');
                Route::post('blog/toggle-publish', [AdminBatchController::class, 'toggleBlogPublish'])->middleware('throttle:10,1')->name('blog.toggle-publish');
            });

            // ── DEPRECATED: use Filament /admin/feature-flags — sunset 2026-07-17 ──
            Route::middleware('deprecated:/admin/feature-flags,2026-07-17')->group(function (): void {
                Route::get('feature-flags', [AdminFeatureFlagController::class, 'index'])->name('feature-flags.index');
                Route::post('feature-flags', [AdminFeatureFlagController::class, 'store'])->name('feature-flags.store');
                Route::put('feature-flags/{featureFlag}', [AdminFeatureFlagController::class, 'update'])->name('feature-flags.update');
                Route::delete('feature-flags/{featureFlag}', [AdminFeatureFlagController::class, 'destroy'])->name('feature-flags.destroy');
                Route::get('feature-flags/check', [AdminFeatureFlagController::class, 'checkForTenant'])->name('feature-flags.check');
            });

            // Currency Management (admin)
            Route::post('currencies', [CurrencyController::class, 'store'])->name('currencies.store');
            Route::post('currencies/set-rate', [CurrencyController::class, 'setRate'])->name('currencies.set-rate');

            // Email Templates
            Route::get('email-templates', [AdminEmailTemplateController::class, 'index'])->name('email-templates.index');
            Route::post('email-templates', [AdminEmailTemplateController::class, 'store'])->name('email-templates.store');
            Route::get('email-templates/{emailTemplate}', [AdminEmailTemplateController::class, 'show'])->name('email-templates.show');
            Route::put('email-templates/{emailTemplate}', [AdminEmailTemplateController::class, 'update'])->name('email-templates.update');
            Route::get('email-templates/{emailTemplate}/preview', [AdminEmailTemplateController::class, 'preview'])->name('email-templates.preview');

            // Tenant Data Export (GDPR)
            Route::post('tenants/{tenant}/export', function (Tenant $tenant) {
                Artisan::call('tenant:export', ['tenant' => $tenant->id]);

                return response()->json(['message' => 'Export started. Check storage/app/exports/ for the file.']);
            })->name('tenants.export');

            // Media Upload
            Route::post('blog/posts/{post}/cover', [AdminMediaController::class, 'uploadBlogCover'])->name('media.blog-cover');
            Route::post('upload/image', [AdminMediaController::class, 'uploadEditorImage'])->name('media.editor-image');
            Route::delete('media/{mediaId}', [AdminMediaController::class, 'destroy'])->name('media.destroy');

            // Blog Management
            Route::get('blog/posts', [AdminBlogController::class, 'listPosts'])->name('blog.posts.index');
            Route::post('blog/posts', [AdminBlogController::class, 'storePost'])->name('blog.posts.store');
            Route::get('blog/posts/{post}', [AdminBlogController::class, 'showPost'])->name('blog.posts.show');
            Route::put('blog/posts/{post}', [AdminBlogController::class, 'updatePost'])->name('blog.posts.update');
            Route::delete('blog/posts/{post}', [AdminBlogController::class, 'destroyPost'])->name('blog.posts.destroy');
            Route::get('blog/categories', [AdminBlogController::class, 'listCategories'])->name('blog.categories.index');
            Route::post('blog/categories', [AdminBlogController::class, 'storeCategory'])->name('blog.categories.store');
            Route::put('blog/categories/{category}', [AdminBlogController::class, 'updateCategory'])->name('blog.categories.update');
            Route::delete('blog/categories/{category}', [AdminBlogController::class, 'destroyCategory'])->name('blog.categories.destroy');
            Route::get('blog/tags', [AdminBlogController::class, 'listTags'])->name('blog.tags.index');
            Route::post('blog/tags', [AdminBlogController::class, 'storeTag'])->name('blog.tags.store');
            Route::delete('blog/tags/{tag}', [AdminBlogController::class, 'destroyTag'])->name('blog.tags.destroy');

            // CMS — Landing Settings
            Route::get('landing', [AdminCmsController::class, 'getLanding'])->name('cms.landing');
            Route::put('landing', [AdminCmsController::class, 'updateLanding'])->name('cms.landing.update');

            // CMS — Pages
            Route::get('pages', [AdminCmsController::class, 'listPages'])->name('cms.pages.index');
            Route::post('pages', [AdminCmsController::class, 'storePage'])->name('cms.pages.store');
            Route::get('pages/{page}', [AdminCmsController::class, 'showPage'])->name('cms.pages.show');
            Route::put('pages/{page}', [AdminCmsController::class, 'updatePage'])->name('cms.pages.update');
            Route::delete('pages/{page}', [AdminCmsController::class, 'destroyPage'])->name('cms.pages.destroy');

            // CMS — Testimonials
            Route::get('testimonials', [AdminCmsController::class, 'listTestimonials'])->name('cms.testimonials.index');
            Route::post('testimonials', [AdminCmsController::class, 'storeTestimonial'])->name('cms.testimonials.store');
            Route::put('testimonials/{testimonial}', [AdminCmsController::class, 'updateTestimonial'])->name('cms.testimonials.update');
            Route::delete('testimonials/{testimonial}', [AdminCmsController::class, 'destroyTestimonial'])->name('cms.testimonials.destroy');

            // CMS — FAQs
            Route::get('faqs', [AdminCmsController::class, 'listFaqs'])->name('cms.faqs.index');
            Route::post('faqs', [AdminCmsController::class, 'storeFaq'])->name('cms.faqs.store');
            Route::put('faqs/{faq}', [AdminCmsController::class, 'updateFaq'])->name('cms.faqs.update');
            Route::delete('faqs/{faq}', [AdminCmsController::class, 'destroyFaq'])->name('cms.faqs.destroy');

            // CMS — Contact Submissions
            Route::get('contacts', [AdminCmsController::class, 'listContacts'])->name('cms.contacts.index');
            Route::get('contacts/{contactSubmission}', [AdminCmsController::class, 'showContact'])->name('cms.contacts.show');
            Route::put('contacts/{contactSubmission}', [AdminCmsController::class, 'updateContact'])->name('cms.contacts.update');
            Route::delete('contacts/{contactSubmission}', [AdminCmsController::class, 'destroyContact'])->name('cms.contacts.destroy');

            // Roles & Permissions
            Route::get('roles', [AdminRoleController::class, 'index'])->name('roles.index');
            Route::post('roles', [AdminRoleController::class, 'store'])->middleware('throttle:30,1')->name('roles.store');
            Route::get('roles/{role}', [AdminRoleController::class, 'show'])->name('roles.show');
            Route::put('roles/{role}', [AdminRoleController::class, 'update'])->middleware('throttle:30,1')->name('roles.update');
            Route::delete('roles/{role}', [AdminRoleController::class, 'destroy'])->name('roles.destroy');
            Route::get('permissions', [AdminRoleController::class, 'permissions'])->name('permissions.index');
        });

        // ──────────────────────────────────
        // Client Portal routes
        // ──────────────────────────────────
        Route::middleware(['tenant', 'active', 'set_timezone', 'set_locale', 'client_portal'])
            ->prefix('portal')
            ->name('portal.')
            ->group(function (): void {
                Route::get('dashboard', [ClientPortalController::class, 'dashboard'])->name('dashboard');
                Route::get('profile', [ClientPortalController::class, 'profile'])->name('profile');

                Route::get('invoices', [ClientPortalInvoiceController::class, 'index'])->name('invoices.index');
                Route::get('invoices/{invoice}', [ClientPortalInvoiceController::class, 'show'])->name('invoices.show');
                Route::post('invoices/{invoice}/pay', [ClientPortalInvoiceController::class, 'pay'])->name('invoices.pay');
                Route::get('invoices/{invoice}/pdf', [ClientPortalInvoiceController::class, 'pdf'])->name('invoices.pdf');
                Route::get('payment-gateways', [ClientPortalInvoiceController::class, 'gateways'])->name('payment-gateways');

                Route::get('documents', [ClientPortalDocumentController::class, 'index'])->name('documents.index');
                Route::post('documents', [ClientPortalDocumentController::class, 'store'])->name('documents.store');
                Route::get('documents/{document}/download', [ClientPortalDocumentController::class, 'download'])->name('documents.download');

                Route::get('messages', [ClientPortalMessageController::class, 'index'])->name('messages.index');
                Route::post('messages', [ClientPortalMessageController::class, 'store'])->name('messages.store');
                Route::get('messages/{message}', [ClientPortalMessageController::class, 'show'])->name('messages.show');
                Route::post('messages/{message}/read', [ClientPortalMessageController::class, 'markAsRead'])->name('messages.read');

                Route::get('notifications', [ClientPortalNotificationController::class, 'index'])->name('notifications.index');
                Route::post('notifications/{notification}/read', [ClientPortalNotificationController::class, 'markAsRead'])->name('notifications.read');
                Route::post('notifications/read-all', [ClientPortalNotificationController::class, 'markAllAsRead'])->name('notifications.read-all');
            });
    });
});

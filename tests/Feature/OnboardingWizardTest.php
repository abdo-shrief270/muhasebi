<?php

declare(strict_types=1);

use App\Domain\Accounting\Models\Account;
use App\Domain\Onboarding\Models\CoaTemplate;
use App\Domain\Onboarding\Models\OnboardingProgress;
use Database\Seeders\CoaTemplateSeeder;

beforeEach(function (): void {
    $this->tenant = createTenant();
    $this->admin = createAdminUser($this->tenant);
    actingAsUser($this->admin);

    // Seed COA templates
    (new CoaTemplateSeeder)->run();
});

describe('GET /api/v1/onboarding-wizard/progress', function (): void {

    it('returns initial onboarding progress', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/onboarding-wizard/progress');

        $response->assertOk()
            ->assertJsonPath('data.current_step', 1)
            ->assertJsonPath('data.company_profile_completed', false)
            ->assertJsonPath('data.coa_selected', false)
            ->assertJsonPath('data.opening_balances_imported', false)
            ->assertJsonPath('data.team_invited', false)
            ->assertJsonPath('data.first_invoice_created', false)
            ->assertJsonPath('data.eta_configured', false)
            ->assertJsonPath('data.is_complete', false)
            ->assertJsonPath('data.completion_percent', 0);
    });
});

describe('GET /api/v1/onboarding-wizard/templates', function (): void {

    it('lists available COA templates', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/onboarding-wizard/templates');

        $response->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonFragment(['industry' => 'general'])
            ->assertJsonFragment(['industry' => 'retail'])
            ->assertJsonFragment(['industry' => 'services'])
            ->assertJsonFragment(['industry' => 'manufacturing']);
    });
});

describe('POST /api/v1/onboarding-wizard/select-template', function (): void {

    it('selects a COA template and creates accounts for tenant', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/onboarding-wizard/select-template', [
                'industry' => 'general',
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Chart of Accounts created successfully.');

        $accountCount = Account::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->count();

        expect($accountCount)->toBeGreaterThan(0);
        expect($response->json('accounts_created'))->toBe($accountCount);

        // Progress should reflect COA selected
        expect($response->json('progress.coa_selected'))->toBeTrue();
    });

    it('rejects duplicate template selection', function (): void {
        // First selection
        $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/onboarding-wizard/select-template', [
                'industry' => 'general',
            ]);

        // Second selection should fail
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/onboarding-wizard/select-template', [
                'industry' => 'retail',
            ]);

        $response->assertUnprocessable();
    });

    it('rejects invalid industry', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/onboarding-wizard/select-template', [
                'industry' => 'invalid_industry',
            ]);

        $response->assertUnprocessable();
    });
});

describe('POST /api/v1/onboarding-wizard/import-balances', function (): void {

    it('validates debits must equal credits', function (): void {
        // First create accounts via template
        $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/onboarding-wizard/select-template', [
                'industry' => 'general',
            ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/onboarding-wizard/import-balances', [
                'balances' => [
                    ['account_code' => '1111', 'debit' => 10000, 'credit' => 0],
                    ['account_code' => '3100', 'debit' => 0, 'credit' => 5000],
                ],
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['balances']);
    });

    it('imports valid opening balances', function (): void {
        // First create accounts via template
        $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/onboarding-wizard/select-template', [
                'industry' => 'general',
            ]);

        // Set up a fiscal year so journal entries can be created
        app(\App\Domain\Onboarding\Services\OnboardingService::class)->setupFiscalYear($this->tenant->id);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/onboarding-wizard/import-balances', [
                'balances' => [
                    ['account_code' => '1111', 'debit' => 50000, 'credit' => 0],
                    ['account_code' => '3100', 'debit' => 0, 'credit' => 50000],
                ],
            ]);

        $response->assertOk()
            ->assertJsonStructure([
                'message',
                'journal_entry_id',
                'entry_number',
                'progress',
            ]);

        expect($response->json('progress.opening_balances_imported'))->toBeTrue();
    });
});

describe('POST /api/v1/onboarding-wizard/complete-step', function (): void {

    it('completes a step and advances progress', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/onboarding-wizard/complete-step', [
                'step' => 'company_profile',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.company_profile_completed', true)
            ->assertJsonPath('data.current_step', 2);
    });

    it('rejects invalid step names', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/onboarding-wizard/complete-step', [
                'step' => 'nonexistent_step',
            ]);

        $response->assertUnprocessable();
    });

    it('sets completed_at when all steps are done', function (): void {
        $tenantId = $this->tenant->id;

        // Complete all steps that have columns
        $steps = ['company_profile', 'coa_selection', 'opening_balances', 'team_invitation', 'first_invoice', 'eta_configuration'];
        foreach ($steps as $step) {
            $this->withHeader('X-Tenant', $this->tenant->slug)
                ->postJson('/api/v1/onboarding-wizard/complete-step', [
                    'step' => $step,
                ]);
        }

        $progress = OnboardingProgress::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->first();

        expect($progress->completed_at)->not->toBeNull();
        expect($progress->company_profile_completed)->toBeTrue();
        expect($progress->coa_selected)->toBeTrue();
        expect($progress->opening_balances_imported)->toBeTrue();
        expect($progress->team_invited)->toBeTrue();
        expect($progress->first_invoice_created)->toBeTrue();
        expect($progress->eta_configured)->toBeTrue();
    });
});

describe('POST /api/v1/onboarding-wizard/skip-step', function (): void {

    it('skips a step and advances progress', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/onboarding-wizard/skip-step', [
                'step' => 'opening_balances',
            ]);

        $response->assertOk();

        $completedSteps = $response->json('data.completed_steps');
        expect($completedSteps)->toContain('opening_balances:skipped');
    });

    it('rejects invalid step names', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/onboarding-wizard/skip-step', [
                'step' => 'invalid_step',
            ]);

        $response->assertUnprocessable();
    });
});

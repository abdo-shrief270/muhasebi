<?php

declare(strict_types=1);

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Client\Models\Client;
use App\Models\User;

beforeEach(function (): void {
    $this->tenant = createTenant();
    $this->admin = createAdminUser($this->tenant);
    actingAsUser($this->admin);
});

describe('GET /api/v1/onboarding/progress', function (): void {

    it('returns initial onboarding progress with all steps false', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/onboarding/progress');

        $response->assertStatus(201)
            ->assertJsonPath('data.company_details_completed', false)
            ->assertJsonPath('data.coa_template_selected', false)
            ->assertJsonPath('data.first_client_added', false)
            ->assertJsonPath('data.first_invoice_created', false)
            ->assertJsonPath('data.team_invited', false)
            ->assertJsonPath('data.sample_data_loaded', false)
            ->assertJsonPath('data.wizard_completed', false)
            ->assertJsonPath('data.current_step', 1)
            ->assertJsonPath('data.completion_percent', 0);
    });

    it('auto-detects progress when a client exists', function (): void {
        Client::factory()->create(['tenant_id' => $this->tenant->id]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/onboarding/progress');

        $response->assertStatus(201)
            ->assertJsonPath('data.first_client_added', true);
    });
});

describe('POST /api/v1/onboarding/complete-step', function (): void {

    it('completes a step manually', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/onboarding/complete-step', [
                'step' => 'company_details',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.company_details_completed', true);
    });
});

describe('POST /api/v1/onboarding/skip', function (): void {

    it('skips the wizard and marks it completed', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/onboarding/skip');

        $response->assertStatus(201)
            ->assertJsonPath('data.wizard_completed', true)
            ->assertJsonPath('data.wizard_skipped', true);
    });
});

describe('POST /api/v1/onboarding/setup-coa', function (): void {

    it('sets up chart of accounts from template', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/onboarding/setup-coa', [
                'template' => 'general',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.coa_template_selected', true);

        $accountCount = Account::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->count();

        expect($accountCount)->toBeGreaterThan(0);
    });
});

describe('POST /api/v1/onboarding/setup-fiscal-year', function (): void {

    it('creates a fiscal year for current year', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/onboarding/setup-fiscal-year');

        $response->assertOk()
            ->assertJsonPath('message', 'Fiscal year created successfully.');

        $currentYear = (int) now()->format('Y');
        $fiscalYear = FiscalYear::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->whereYear('start_date', $currentYear)
            ->first();

        expect($fiscalYear)->not->toBeNull();
    });
});

describe('POST /api/v1/onboarding/load-sample-data', function (): void {

    it('loads sample data including clients and invoices', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/onboarding/load-sample-data');

        $response->assertOk()
            ->assertJsonPath('data.sample_data_loaded', true);

        $clientCount = Client::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->count();

        $invoiceCount = Invoice::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->count();

        expect($clientCount)->toBeGreaterThan(0);
        expect($invoiceCount)->toBeGreaterThan(0);
    });
});

describe('POST /api/v1/onboarding/invite-team-member', function (): void {

    it('invites a new team member', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/onboarding/invite-team-member', [
                'email' => 'accountant@example.com',
                'name' => 'أحمد محاسب',
                'role' => 'accountant',
            ]);

        $response->assertCreated()
            ->assertJsonPath('user.email', 'accountant@example.com')
            ->assertJsonPath('user.name', 'أحمد محاسب')
            ->assertJsonPath('user.role', 'accountant');

        $user = User::withoutGlobalScopes()
            ->where('email', 'accountant@example.com')
            ->where('tenant_id', $this->tenant->id)
            ->first();

        expect($user)->not->toBeNull();
    });

    it('rejects duplicate email within the same tenant', function (): void {
        // First invite
        $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/onboarding/invite-team-member', [
                'email' => 'duplicate@example.com',
                'name' => 'أول مستخدم',
                'role' => 'accountant',
            ]);

        // Second invite with same email
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/onboarding/invite-team-member', [
                'email' => 'duplicate@example.com',
                'name' => 'ثاني مستخدم',
                'role' => 'auditor',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    });
});

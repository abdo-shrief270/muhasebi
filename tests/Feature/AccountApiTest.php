<?php

declare(strict_types=1);

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\JournalEntryStatus;
use App\Domain\Accounting\Enums\NormalBalance;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\FiscalPeriod;
use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalEntryLine;

beforeEach(function (): void {
    $this->tenant = createTenant();
    $this->admin = createAdminUser($this->tenant);
    actingAsUser($this->admin);
});

describe('GET /api/v1/accounts', function (): void {

    it('lists accounts for the tenant with pagination', function (): void {
        Account::factory()->count(3)->create(['tenant_id' => $this->tenant->id]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/accounts');

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [['id', 'code', 'name_ar', 'name_en', 'type', 'normal_balance', 'is_active', 'is_group', 'level']],
                'links',
                'meta',
            ]);
    });

    it('paginates results', function (): void {
        Account::factory()->count(20)->create(['tenant_id' => $this->tenant->id]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/accounts?per_page=5');

        $response->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonPath('meta.total', 20);
    });

    it('filters by search term', function (): void {
        Account::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name_ar' => 'الصندوق',
            'code' => '1111',
        ]);
        Account::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name_ar' => 'البنك',
            'code' => '1112',
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/accounts?search=' . urlencode('الصندوق'));

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name_ar', 'الصندوق');
    });

    it('filters by account type', function (): void {
        Account::factory()->asset()->create(['tenant_id' => $this->tenant->id]);
        Account::factory()->asset()->create(['tenant_id' => $this->tenant->id]);
        Account::factory()->revenue()->create(['tenant_id' => $this->tenant->id]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/accounts?type=asset');

        $response->assertOk()
            ->assertJsonCount(2, 'data');
    });

    it('does not show accounts from other tenants', function (): void {
        $otherTenant = createTenant();
        Account::factory()->count(2)->create(['tenant_id' => $otherTenant->id]);
        Account::factory()->create(['tenant_id' => $this->tenant->id]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/accounts');

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    });
});

describe('POST /api/v1/accounts', function (): void {

    it('creates an account with all fields', function (): void {
        $data = [
            'code' => '1111',
            'name_ar' => 'الصندوق',
            'name_en' => 'Cash on Hand',
            'type' => 'asset',
            'normal_balance' => 'debit',
            'description' => 'حساب الصندوق الرئيسي',
            'currency' => 'EGP',
        ];

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/accounts', $data);

        $response->assertCreated()
            ->assertJsonPath('data.code', '1111')
            ->assertJsonPath('data.name_ar', 'الصندوق')
            ->assertJsonPath('data.name_en', 'Cash on Hand')
            ->assertJsonPath('data.type', 'asset')
            ->assertJsonPath('data.normal_balance', 'debit')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.is_group', false)
            ->assertJsonPath('data.level', 1);

        $this->assertDatabaseHas('accounts', [
            'tenant_id' => $this->tenant->id,
            'code' => '1111',
            'name_ar' => 'الصندوق',
        ]);
    });

    it('creates an account with parent and auto-calculates level', function (): void {
        $parent = Account::factory()->group()->create([
            'tenant_id' => $this->tenant->id,
            'code' => '1100',
            'level' => 2,
        ]);

        $data = [
            'code' => '1110',
            'name_ar' => 'النقدية',
            'type' => 'asset',
            'normal_balance' => 'debit',
            'parent_id' => $parent->id,
        ];

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/accounts', $data);

        $response->assertCreated()
            ->assertJsonPath('data.level', 3)
            ->assertJsonPath('data.parent_id', $parent->id);
    });

    it('validates required fields', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/accounts', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['code', 'name_ar', 'type', 'normal_balance']);
    });

    it('validates unique code per tenant', function (): void {
        Account::factory()->create([
            'tenant_id' => $this->tenant->id,
            'code' => '1111',
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/accounts', [
                'code' => '1111',
                'name_ar' => 'حساب جديد',
                'type' => 'asset',
                'normal_balance' => 'debit',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);
    });

    it('allows same code in different tenants', function (): void {
        $otherTenant = createTenant();
        Account::factory()->create([
            'tenant_id' => $otherTenant->id,
            'code' => '1111',
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/accounts', [
                'code' => '1111',
                'name_ar' => 'الصندوق',
                'type' => 'asset',
                'normal_balance' => 'debit',
            ]);

        $response->assertCreated();
    });
});

describe('GET /api/v1/accounts/{account}', function (): void {

    it('shows a single account', function (): void {
        $account = Account::factory()->create(['tenant_id' => $this->tenant->id]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson("/api/v1/accounts/{$account->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $account->id)
            ->assertJsonPath('data.code', $account->code);
    });

    it('returns 404 for account in other tenant', function (): void {
        $otherTenant = createTenant();
        $account = Account::factory()->create(['tenant_id' => $otherTenant->id]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson("/api/v1/accounts/{$account->id}");

        $response->assertNotFound();
    });
});

describe('PUT /api/v1/accounts/{account}', function (): void {

    it('updates an account', function (): void {
        $account = Account::factory()->create([
            'tenant_id' => $this->tenant->id,
            'code' => '1111',
            'name_ar' => 'الصندوق',
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->putJson("/api/v1/accounts/{$account->id}", [
                'name_ar' => 'الصندوق الرئيسي',
                'name_en' => 'Main Cash',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.name_ar', 'الصندوق الرئيسي')
            ->assertJsonPath('data.name_en', 'Main Cash');
    });

    it('cannot update type if account has posted journal entries', function (): void {
        $account = Account::factory()->asset()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $fiscalYear = FiscalYear::factory()->create(['tenant_id' => $this->tenant->id]);
        $period = FiscalPeriod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'fiscal_year_id' => $fiscalYear->id,
        ]);

        $entry = JournalEntry::factory()->posted()->create([
            'tenant_id' => $this->tenant->id,
            'fiscal_period_id' => $period->id,
            'created_by' => $this->admin->id,
            'posted_by' => $this->admin->id,
        ]);

        JournalEntryLine::factory()->debit(1000)->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $account->id,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->putJson("/api/v1/accounts/{$account->id}", [
                'type' => 'liability',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['type']);
    });
});

describe('DELETE /api/v1/accounts/{account}', function (): void {

    it('soft deletes an account', function (): void {
        $account = Account::factory()->create(['tenant_id' => $this->tenant->id]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->deleteJson("/api/v1/accounts/{$account->id}");

        $response->assertOk()
            ->assertJsonPath('message', 'Account deleted successfully.');

        $this->assertSoftDeleted('accounts', ['id' => $account->id]);
    });

    it('cannot delete account with journal entry lines', function (): void {
        $account = Account::factory()->create(['tenant_id' => $this->tenant->id]);

        $entry = JournalEntry::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        JournalEntryLine::factory()->debit(500)->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $account->id,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->deleteJson("/api/v1/accounts/{$account->id}");

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['account']);
    });
});

describe('GET /api/v1/accounts/tree', function (): void {

    it('returns account tree with nested children', function (): void {
        $root = Account::factory()->group()->create([
            'tenant_id' => $this->tenant->id,
            'code' => '1000',
            'name_ar' => 'الأصول',
            'level' => 1,
            'parent_id' => null,
        ]);

        $child = Account::factory()->create([
            'tenant_id' => $this->tenant->id,
            'code' => '1100',
            'name_ar' => 'الأصول المتداولة',
            'level' => 2,
            'parent_id' => $root->id,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/accounts/tree');

        $response->assertOk()
            ->assertJsonPath('data.0.code', '1000')
            ->assertJsonPath('data.0.children.0.code', '1100');
    });
});

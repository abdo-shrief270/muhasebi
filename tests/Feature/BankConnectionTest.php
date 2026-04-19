<?php

declare(strict_types=1);

use App\Domain\Accounting\Models\Account;
use App\Domain\Banking\Enums\BankCode;
use App\Domain\Banking\Models\BankConnection;
use Illuminate\Http\UploadedFile;

beforeEach(function (): void {
    $this->tenant = createTenant();
    $this->admin = createAdminUser($this->tenant);
    actingAsUser($this->admin);
});

// ── BankCode Enum ──

describe('BankCode Enum', function (): void {

    it('returns English labels for all bank codes', function (): void {
        expect(BankCode::NBE->label())->toBe('National Bank of Egypt');
        expect(BankCode::CIB->label())->toBe('Commercial International Bank');
        expect(BankCode::BanqueDuCaire->label())->toBe('Banque du Caire');
        expect(BankCode::AAIB->label())->toBe('Arab African International Bank');
        expect(BankCode::QNB->label())->toBe('Qatar National Bank Alahli');
        expect(BankCode::HSBC->label())->toBe('HSBC Egypt');
        expect(BankCode::Other->label())->toBe('Other');
    });

    it('returns Arabic labels for all bank codes', function (): void {
        expect(BankCode::NBE->labelAr())->toBe('البنك الأهلي المصري');
        expect(BankCode::CIB->labelAr())->toBe('البنك التجاري الدولي');
        expect(BankCode::BanqueDuCaire->labelAr())->toBe('بنك القاهرة');
        expect(BankCode::Other->labelAr())->toBe('أخرى');
    });

    it('returns false for supportsApi on all banks (placeholder)', function (): void {
        foreach (BankCode::cases() as $code) {
            expect($code->supportsApi())->toBeFalse();
        }
    });

});

// ── CRUD ──

describe('GET /api/v1/bank-connections', function (): void {

    it('lists bank connections for the tenant', function (): void {
        BankConnection::create([
            'tenant_id' => $this->tenant->id,
            'bank_code' => 'nbe',
            'account_number' => '1234567890',
            'account_name' => 'Main Account',
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/bank-connections');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.bank_code', 'nbe');
    });

    it('does not show connections from other tenants', function (): void {
        $otherTenant = createTenant();
        BankConnection::create([
            'tenant_id' => $otherTenant->id,
            'bank_code' => 'cib',
            'account_number' => '9999999999',
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/bank-connections');

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    });

});

describe('POST /api/v1/bank-connections', function (): void {

    it('creates a bank connection', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/bank-connections', [
                'bank_code' => 'nbe',
                'account_number' => '1234567890',
                'account_name' => 'Company Current Account',
                'currency' => 'EGP',
                'connection_type' => 'manual',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.bank_code', 'nbe')
            ->assertJsonPath('data.account_number', '1234567890')
            ->assertJsonPath('data.account_name', 'Company Current Account');

        $this->assertDatabaseHas('bank_connections', [
            'tenant_id' => $this->tenant->id,
            'bank_code' => 'nbe',
            'account_number' => '1234567890',
        ]);
    });

    it('validates required fields', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/bank-connections', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['bank_code', 'account_number']);
    });

    it('rejects invalid bank code', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/bank-connections', [
                'bank_code' => 'invalid_bank',
                'account_number' => '1234567890',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['bank_code']);
    });

    it('prevents duplicate bank_code + account_number per tenant', function (): void {
        BankConnection::create([
            'tenant_id' => $this->tenant->id,
            'bank_code' => 'cib',
            'account_number' => '5555555555',
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/bank-connections', [
                'bank_code' => 'cib',
                'account_number' => '5555555555',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['account_number']);
    });

});

describe('PUT /api/v1/bank-connections/{id}', function (): void {

    it('updates a bank connection', function (): void {
        $connection = BankConnection::create([
            'tenant_id' => $this->tenant->id,
            'bank_code' => 'nbe',
            'account_number' => '1234567890',
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->putJson("/api/v1/bank-connections/{$connection->id}", [
                'bank_code' => 'nbe',
                'account_number' => '1234567890',
                'account_name' => 'Updated Name',
                'iban' => 'EG380019000500000000263180002',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.account_name', 'Updated Name')
            ->assertJsonPath('data.iban', 'EG380019000500000000263180002');
    });

});

describe('DELETE /api/v1/bank-connections/{id}', function (): void {

    it('soft-deletes a bank connection', function (): void {
        $connection = BankConnection::create([
            'tenant_id' => $this->tenant->id,
            'bank_code' => 'nbe',
            'account_number' => '1234567890',
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->deleteJson("/api/v1/bank-connections/{$connection->id}");

        $response->assertOk()
            ->assertJsonPath('message', 'Bank connection deleted.');

        $this->assertSoftDeleted('bank_connections', ['id' => $connection->id]);
    });

});

// ── Sync Balance ──

describe('POST /api/v1/bank-connections/{id}/sync-balance', function (): void {

    it('returns stored balance when API not available', function (): void {
        $connection = BankConnection::create([
            'tenant_id' => $this->tenant->id,
            'bank_code' => 'nbe',
            'account_number' => '1234567890',
            'balance' => 50000.00,
            'balance_date' => '2026-04-01',
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/bank-connections/{$connection->id}/sync-balance");

        $response->assertOk()
            ->assertJsonPath('data.synced', false)
            ->assertJsonPath('data.balance', '50000.00');
    });

});

// ── CSV Import Parsing ──

describe('POST /api/v1/bank-connections/{id}/import-statement', function (): void {

    it('requires a linked GL account', function (): void {
        $connection = BankConnection::create([
            'tenant_id' => $this->tenant->id,
            'bank_code' => 'nbe',
            'account_number' => '1234567890',
            'linked_gl_account_id' => null,
        ]);

        $file = UploadedFile::fake()->createWithContent('statement.csv', "date,description,amount\n2026-04-01,Test,100");

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/bank-connections/{$connection->id}/import-statement", [
                'file' => $file,
                'format' => 'csv',
            ]);

        $response->assertUnprocessable();
    });

    it('imports CSV bank statement lines', function (): void {
        $account = Account::factory()->create(['tenant_id' => $this->tenant->id]);
        $connection = BankConnection::create([
            'tenant_id' => $this->tenant->id,
            'bank_code' => 'cib',
            'account_number' => '9876543210',
            'linked_gl_account_id' => $account->id,
        ]);

        $csv = "date,description,reference,amount\n2026-04-01,Salary Payment,SAL001,-15000.00\n2026-04-02,Client Payment,INV042,25000.00\n2026-04-03,Office Rent,RENT04,-5000.00";
        $file = UploadedFile::fake()->createWithContent('statement.csv', $csv);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/bank-connections/{$connection->id}/import-statement", [
                'file' => $file,
                'format' => 'csv',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.lines_imported', 3)
            ->assertJsonStructure(['data' => ['lines_imported', 'reconciliation_id']]);
    });

    it('imports CSV with debit/credit columns', function (): void {
        $account = Account::factory()->create(['tenant_id' => $this->tenant->id]);
        $connection = BankConnection::create([
            'tenant_id' => $this->tenant->id,
            'bank_code' => 'nbe',
            'account_number' => '1111111111',
            'linked_gl_account_id' => $account->id,
        ]);

        $csv = "date,description,debit,credit\n2026-04-01,Withdrawal,5000,0\n2026-04-02,Deposit,0,10000";
        $file = UploadedFile::fake()->createWithContent('statement.csv', $csv);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/bank-connections/{$connection->id}/import-statement", [
                'file' => $file,
                'format' => 'csv',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.lines_imported', 2);
    });

});

// ── Payment Instruction ──

describe('POST /api/v1/bank-connections/generate-instruction', function (): void {

    it('generates MT103 SWIFT payment instruction', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/bank-connections/generate-instruction', [
                'format' => 'mt103',
                'amount' => 50000,
                'currency' => 'EGP',
                'sender_name' => 'Acme Corp',
                'sender_account' => '1234567890',
                'receiver_name' => 'Supplier Ltd',
                'receiver_account' => '9876543210',
                'receiver_bank_code' => 'NBEGEGCX',
                'details' => 'Invoice payment INV-2026-042',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.format', 'mt103');

        $content = $response->json('data.content');
        expect($content)->toContain(':20:')
            ->toContain(':32A:')
            ->toContain('50000')
            ->toContain('Acme Corp')
            ->toContain('Supplier Ltd');
    });

    it('generates local bank transfer instruction', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/bank-connections/generate-instruction', [
                'format' => 'local',
                'amount' => 25000,
                'sender_name' => 'شركة المحاسبي',
                'sender_account' => '1234567890',
                'receiver_name' => 'المورد',
                'receiver_account' => '9876543210',
                'details' => 'دفعة فاتورة',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.format', 'local');

        $content = $response->json('data.content');
        expect($content)->toContain('شركة المحاسبي')
            ->toContain('المورد')
            ->toContain('25,000.00');
    });

    it('validates required payment fields', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/bank-connections/generate-instruction', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['amount', 'sender_name', 'sender_account', 'receiver_name', 'receiver_account']);
    });

});

// ── Supported Formats ──

describe('GET /api/v1/bank-connections/supported-formats', function (): void {

    it('returns supported formats for a bank', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/bank-connections/supported-formats?bank_code=hsbc');

        $response->assertOk()
            ->assertJsonStructure(['data' => [['format', 'label']]]);

        $formats = collect($response->json('data'))->pluck('format')->toArray();
        expect($formats)->toContain('csv')
            ->toContain('ofx')
            ->toContain('mt940');
    });

    it('returns CSV only for unknown/other banks', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/bank-connections/supported-formats?bank_code=other');

        $response->assertOk();

        $formats = collect($response->json('data'))->pluck('format')->toArray();
        expect($formats)->toBe(['csv']);
    });

});

// ── Dashboard ──

describe('GET /api/v1/bank-connections/dashboard', function (): void {

    it('returns dashboard with connections and summary', function (): void {
        BankConnection::create([
            'tenant_id' => $this->tenant->id,
            'bank_code' => 'nbe',
            'account_number' => '1111111111',
            'balance' => 100000,
            'is_active' => true,
            'sync_status' => 'connected',
        ]);
        BankConnection::create([
            'tenant_id' => $this->tenant->id,
            'bank_code' => 'cib',
            'account_number' => '2222222222',
            'balance' => 50000,
            'is_active' => true,
            'sync_status' => 'disconnected',
        ]);
        BankConnection::create([
            'tenant_id' => $this->tenant->id,
            'bank_code' => 'hsbc',
            'account_number' => '3333333333',
            'balance' => 25000,
            'is_active' => false,
            'sync_status' => 'error',
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/bank-connections/dashboard');

        $response->assertOk()
            ->assertJsonStructure([
                'connections',
                'summary' => ['total_connections', 'active_connections', 'error_connections', 'total_balance', 'currency'],
            ])
            ->assertJsonPath('summary.total_connections', 3)
            ->assertJsonPath('summary.active_connections', 2)
            ->assertJsonPath('summary.error_connections', 1)
            ->assertJsonPath('summary.total_balance', '175000.00')
            ->assertJsonPath('summary.currency', 'EGP');
    });

    it('returns empty dashboard when no connections exist', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/bank-connections/dashboard');

        $response->assertOk()
            ->assertJsonPath('summary.total_connections', 0)
            ->assertJsonPath('summary.total_balance', '0.00');
    });

});

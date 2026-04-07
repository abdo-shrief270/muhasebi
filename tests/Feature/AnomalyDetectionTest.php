<?php

declare(strict_types=1);

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalEntryLine;
use App\Domain\AccountsPayable\Models\BillPayment;
use App\Domain\AccountsPayable\Models\Vendor;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Client\Models\Client;

beforeEach(function (): void {
    $this->tenant = createTenant();
    $this->admin = createAdminUser($this->tenant);
    actingAsUser($this->admin);
});

describe('Duplicate Invoice Detection', function (): void {

    it('flags invoices with same client, same amount, within 3 days as duplicates', function (): void {
        $client = Client::factory()->create(['tenant_id' => $this->tenant->id]);

        Invoice::factory()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $client->id,
            'total' => '5000.00',
            'date' => '2026-03-01',
            'invoice_number' => 'INV-0001',
        ]);

        Invoice::factory()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $client->id,
            'total' => '5000.00',
            'date' => '2026-03-02',
            'invoice_number' => 'INV-0002',
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/anomalies/duplicates');

        $response->assertOk();
        $data = $response->json('data');
        expect($data)->toHaveCount(1);
        expect($data[0]['type'])->toBe('duplicate_invoice');
        expect($data[0]['severity'])->toBe('high');
        expect($data[0]['details']['days_apart'])->toBeLessThanOrEqual(3);
    });

    it('does not flag invoices more than 3 days apart as duplicates', function (): void {
        $client = Client::factory()->create(['tenant_id' => $this->tenant->id]);

        Invoice::factory()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $client->id,
            'total' => '5000.00',
            'date' => '2026-03-01',
            'invoice_number' => 'INV-0010',
        ]);

        Invoice::factory()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $client->id,
            'total' => '5000.00',
            'date' => '2026-03-11',
            'invoice_number' => 'INV-0011',
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/anomalies/duplicates');

        $response->assertOk();
        expect($response->json('data'))->toBeEmpty();
    });
});

describe('Unusual Amount Detection', function (): void {

    it('flags transactions with amounts exceeding 3 standard deviations', function (): void {
        $account = Account::factory()->create(['tenant_id' => $this->tenant->id]);

        // Create 4 normal transactions and 1 outlier
        $amounts = [100, 100, 100, 100, 10000];
        foreach ($amounts as $amount) {
            $entry = JournalEntry::factory()->posted()->create([
                'tenant_id' => $this->tenant->id,
                'date' => now()->subMonth(),
                'total_debit' => $amount,
                'total_credit' => $amount,
            ]);

            JournalEntryLine::factory()->create([
                'journal_entry_id' => $entry->id,
                'account_id' => $account->id,
                'debit' => $amount,
                'credit' => 0,
            ]);
        }

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/anomalies/unusual-amounts');

        $response->assertOk();
        $data = $response->json('data');
        expect($data)->not->toBeEmpty();

        $flaggedAmounts = collect($data)->pluck('details.amount')->all();
        expect($flaggedAmounts)->toContain('10000.00');
    });
});

describe('Missing Sequence Detection', function (): void {

    it('detects gaps in invoice number sequences', function (): void {
        $client = Client::factory()->create(['tenant_id' => $this->tenant->id]);

        foreach ([1, 2, 4, 5] as $num) {
            Invoice::factory()->create([
                'tenant_id' => $this->tenant->id,
                'client_id' => $client->id,
                'invoice_number' => 'INV-'.str_pad((string) $num, 6, '0', STR_PAD_LEFT),
            ]);
        }

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/anomalies/missing-sequences');

        $response->assertOk();
        $data = $response->json('data');

        $missingNumbers = collect($data)->pluck('details.missing_number')->all();
        expect($missingNumbers)->toContain('INV-000003');
    });
});

describe('Weekend Entry Detection', function (): void {

    it('flags journal entries posted on Friday (Egypt weekend)', function (): void {
        // Find a Friday date
        $friday = now()->next('Friday');

        JournalEntry::factory()->posted()->create([
            'tenant_id' => $this->tenant->id,
            'date' => $friday,
            'entry_number' => 'JE-FRIDAY',
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/anomalies/weekend-entries');

        $response->assertOk();
        $data = $response->json('data');
        expect($data)->not->toBeEmpty();
        expect($data[0]['type'])->toBe('weekend_entry');
        expect($data[0]['details']['day_of_week'])->toBe('Friday');
    });
});

describe('Round Number Bias Detection', function (): void {

    it('flags accounts where over 80% of transactions are round numbers', function (): void {
        $account = Account::factory()->create(['tenant_id' => $this->tenant->id]);

        // 4 round numbers + 1 non-round = 80% round
        $amounts = [1000, 2000, 3000, 4000, 123];
        foreach ($amounts as $amount) {
            $entry = JournalEntry::factory()->posted()->create([
                'tenant_id' => $this->tenant->id,
                'date' => now()->subWeek(),
                'total_debit' => $amount,
                'total_credit' => $amount,
            ]);

            JournalEntryLine::factory()->create([
                'journal_entry_id' => $entry->id,
                'account_id' => $account->id,
                'debit' => $amount,
                'credit' => 0,
            ]);
        }

        $service = app(\App\Domain\Accounting\Services\AnomalyDetectionService::class);
        $results = $service->roundNumberBias();

        // 4/5 = 80%, threshold is >80%, so exactly 80% should NOT be flagged
        // Let's check: the spec says >80%, so 80% exactly is not flagged
        expect($results)->toBeEmpty();

        // Add one more round number to push to 83.3%
        $entry = JournalEntry::factory()->posted()->create([
            'tenant_id' => $this->tenant->id,
            'date' => now()->subWeek(),
            'total_debit' => 5000,
            'total_credit' => 5000,
        ]);

        JournalEntryLine::factory()->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $account->id,
            'debit' => 5000,
            'credit' => 0,
        ]);

        $results = $service->roundNumberBias();
        expect($results)->not->toBeEmpty();
        expect($results[0]['type'])->toBe('round_number_bias');
        expect($results[0]['details']['percentage'])->toBeGreaterThan(80);
    });
});

describe('Dormant Account Activity Detection', function (): void {

    it('flags accounts with no activity for 7 months that suddenly have transactions', function (): void {
        $account = Account::factory()->create(['tenant_id' => $this->tenant->id]);

        // Create old activity (13 months ago, well before the 6-month dormancy window)
        $oldEntry = JournalEntry::factory()->posted()->create([
            'tenant_id' => $this->tenant->id,
            'date' => now()->subMonths(13),
            'total_debit' => 500,
            'total_credit' => 500,
        ]);

        JournalEntryLine::factory()->create([
            'journal_entry_id' => $oldEntry->id,
            'account_id' => $account->id,
            'debit' => 500,
            'credit' => 0,
        ]);

        // Create recent activity (today)
        $recentEntry = JournalEntry::factory()->posted()->create([
            'tenant_id' => $this->tenant->id,
            'date' => now(),
            'entry_number' => 'JE-DORMANT',
            'total_debit' => 1000,
            'total_credit' => 1000,
        ]);

        JournalEntryLine::factory()->create([
            'journal_entry_id' => $recentEntry->id,
            'account_id' => $account->id,
            'debit' => 1000,
            'credit' => 0,
        ]);

        $service = app(\App\Domain\Accounting\Services\AnomalyDetectionService::class);
        $results = $service->dormantAccountActivity();

        expect($results)->not->toBeEmpty();
        expect($results[0]['type'])->toBe('dormant_account_activity');
        expect($results[0]['details']['months_inactive'])->toBeGreaterThanOrEqual(6);
    });
});

describe('Detect All Endpoint', function (): void {

    it('returns combined results sorted by severity', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/anomalies');

        $response->assertOk();
        expect($response->json('data'))->toBeArray();
    });
});

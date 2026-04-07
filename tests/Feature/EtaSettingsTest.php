<?php

declare(strict_types=1);

use App\Domain\EInvoice\Models\EtaSettings;

beforeEach(function (): void {
    $this->tenant = createTenant();
    $this->admin = createAdminUser($this->tenant);
    actingAsUser($this->admin);
});

describe('GET /api/v1/eta/settings', function (): void {

    it('returns default settings when none exist', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/eta/settings');

        $response->assertStatus(201)
            ->assertJsonPath('data.is_enabled', false)
            ->assertJsonPath('data.environment', 'preprod')
            ->assertJsonPath('data.branch_id', '0')
            ->assertJsonPath('data.branch_address_country', 'EG')
            ->assertJsonPath('data.client_id', null)
            ->assertJsonPath('data.has_client_secret', false);
    });

    it('masks client_id in response', function (): void {
        EtaSettings::query()->create([
            'tenant_id' => $this->tenant->id,
            'is_enabled' => true,
            'client_id' => 'abcdef12345',
            'client_secret' => 'secret-value',
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/eta/settings');

        $response->assertOk()
            ->assertJsonPath('data.client_id', '****2345')
            ->assertJsonPath('data.has_client_secret', true);
    });

    it('does not expose client_secret or access_token', function (): void {
        EtaSettings::query()->create([
            'tenant_id' => $this->tenant->id,
            'client_secret' => 'super-secret',
            'access_token' => 'token-value',
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/eta/settings');

        $response->assertOk();
        $data = $response->json('data');
        expect($data)->not->toHaveKey('client_secret');
        expect($data)->not->toHaveKey('access_token');
    });
});

describe('PUT /api/v1/eta/settings', function (): void {

    it('updates ETA settings', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->putJson('/api/v1/eta/settings', [
                'is_enabled' => true,
                'environment' => 'preprod',
                'client_id' => 'test-client-id',
                'client_secret' => 'test-client-secret',
                'branch_id' => '1',
                'activity_code' => '4620',
                'company_trade_name' => 'شركة تجريبية',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.is_enabled', true)
            ->assertJsonPath('data.environment', 'preprod')
            ->assertJsonPath('data.branch_id', '1')
            ->assertJsonPath('data.activity_code', '4620')
            ->assertJsonPath('data.company_trade_name', 'شركة تجريبية');

        $settings = EtaSettings::query()->where('tenant_id', $this->tenant->id)->first();
        expect($settings->client_id)->toBe('test-client-id');
        expect($settings->client_secret)->toBe('test-client-secret');
    });

    it('clears cached token when credentials change', function (): void {
        EtaSettings::query()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => 'old-id',
            'client_secret' => 'old-secret',
            'access_token' => 'cached-token',
            'token_expires_at' => now()->addHour(),
        ]);

        $this->withHeader('X-Tenant', $this->tenant->slug)
            ->putJson('/api/v1/eta/settings', [
                'client_id' => 'new-id',
            ]);

        $settings = EtaSettings::query()->where('tenant_id', $this->tenant->id)->first();
        expect($settings->access_token)->toBeNull();
        expect($settings->token_expires_at)->toBeNull();
    });

    it('validates environment value', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->putJson('/api/v1/eta/settings', [
                'environment' => 'invalid',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['environment']);
    });

    it('does not show settings from other tenants', function (): void {
        $otherTenant = createTenant();
        EtaSettings::query()->create([
            'tenant_id' => $otherTenant->id,
            'is_enabled' => true,
            'activity_code' => '9999',
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/eta/settings');

        $response->assertStatus(201)
            ->assertJsonPath('data.is_enabled', false)
            ->assertJsonPath('data.activity_code', null);
    });
});

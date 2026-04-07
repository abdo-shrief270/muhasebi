<?php

declare(strict_types=1);

use App\Domain\Client\Models\Client;
use App\Domain\ClientPortal\Models\Message;
use App\Domain\Shared\Enums\UserRole;
use App\Models\User;

beforeEach(function (): void {
    $this->tenant = createTenant();
    $this->admin = createAdminUser($this->tenant);
    actingAsUser($this->admin);

    $this->client = Client::factory()->create(['tenant_id' => $this->tenant->id]);
});

describe('POST /api/v1/clients/{client}/invite-portal', function (): void {

    it('creates a client portal user', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/clients/{$this->client->id}/invite-portal", [
                'email' => 'client@example.com',
                'name' => 'محمد العميل',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.email', 'client@example.com')
            ->assertJsonPath('data.role', 'client');

        $user = User::query()->where('email', 'client@example.com')->first();
        expect($user)->not->toBeNull();
        expect($user->role)->toBe(UserRole::Client);
        expect($user->client_id)->toBe($this->client->id);
        expect($user->tenant_id)->toBe($this->tenant->id);
    });

    it('rejects duplicate email', function (): void {
        User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'email' => 'taken@example.com',
        ]);

        $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/clients/{$this->client->id}/invite-portal", [
                'email' => 'taken@example.com',
                'name' => 'Test',
            ])
            ->assertUnprocessable();
    });

    it('validates required fields', function (): void {
        $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/clients/{$this->client->id}/invite-portal", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'name']);
    });
});

describe('Firm-side messaging', function (): void {

    it('sends a message to a client', function (): void {
        // Create a portal user so notification can be sent
        User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'role' => UserRole::Client,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/clients/{$this->client->id}/messages", [
                'subject' => 'تذكير بالدفع',
                'body' => 'يرجى سداد الفاتورة المستحقة.',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.direction', 'outbound')
            ->assertJsonPath('data.subject', 'تذكير بالدفع');
    });

    it('lists messages with a client', function (): void {
        Message::factory()->count(2)->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'user_id' => $this->admin->id,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson("/api/v1/clients/{$this->client->id}/messages");

        $response->assertOk()
            ->assertJsonCount(2, 'data');
    });
});

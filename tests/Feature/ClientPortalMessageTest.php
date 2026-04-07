<?php

declare(strict_types=1);

use App\Domain\Client\Models\Client;
use App\Domain\ClientPortal\Enums\MessageDirection;
use App\Domain\ClientPortal\Models\Message;
use App\Domain\Shared\Enums\UserRole;
use App\Models\User;

beforeEach(function (): void {
    $this->tenant = createTenant();
    $this->client = Client::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->clientUser = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'client_id' => $this->client->id,
        'role' => UserRole::Client,
    ]);
    actingAsUser($this->clientUser);
});

describe('GET /api/v1/portal/messages', function (): void {

    it('lists messages for this client', function (): void {
        Message::factory()->count(3)->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'user_id' => $this->clientUser->id,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/portal/messages');

        $response->assertOk()
            ->assertJsonCount(3, 'data');
    });

    it('does not show messages from other clients', function (): void {
        $otherClient = Client::factory()->create(['tenant_id' => $this->tenant->id]);
        Message::factory()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $otherClient->id,
            'user_id' => $this->clientUser->id,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/portal/messages');

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    });
});

describe('POST /api/v1/portal/messages', function (): void {

    it('sends a message to the firm', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/portal/messages', [
                'subject' => 'استفسار عن الفاتورة',
                'body' => 'أريد الاستفسار عن فاتورة الشهر الماضي.',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.subject', 'استفسار عن الفاتورة')
            ->assertJsonPath('data.direction', 'inbound');

        expect(Message::query()->count())->toBe(1);
    });

    it('validates required fields', function (): void {
        $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/portal/messages', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['subject', 'body']);
    });
});

describe('POST /api/v1/portal/messages/{message}/read', function (): void {

    it('marks a message as read', function (): void {
        $message = Message::factory()->outbound()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'user_id' => $this->clientUser->id,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/portal/messages/{$message->id}/read");

        $response->assertOk()
            ->assertJsonPath('data.is_read', true);
    });
});

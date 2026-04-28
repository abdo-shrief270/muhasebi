<?php

declare(strict_types=1);

use App\Domain\Client\Models\Client;
use App\Domain\ClientPortal\Models\Message;
use App\Domain\ClientPortal\Models\PortalInviteToken;
use App\Domain\ClientPortal\Services\ClientInvitationService;
use App\Domain\Shared\Enums\UserRole;
use App\Mail\ClientPortalInviteMail;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

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

    it('returns an invite_url carrying the magic-link token', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/clients/{$this->client->id}/invite-portal", [
                'email' => 'linked@example.com',
                'name' => 'Linked Client',
            ]);

        $response->assertCreated();
        $url = $response->json('invite_url');
        expect($url)->toBeString()
            ->and($url)->toContain('/portal/accept-invite?token=');

        $this->assertDatabaseHas('portal_invite_tokens', [
            'user_id' => User::query()->where('email', 'linked@example.com')->value('id'),
            'used_at' => null,
        ]);
    });

    it('dispatches ClientPortalInviteMail with the magic-link URL', function (): void {
        Mail::fake();

        $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/clients/{$this->client->id}/invite-portal", [
                'email' => 'mailed@example.com',
                'name' => 'Mailed Client',
            ])
            ->assertCreated();

        Mail::assertQueued(ClientPortalInviteMail::class, function ($mail) {
            return $mail->hasTo('mailed@example.com')
                && str_contains($mail->actionUrl, '/portal/accept-invite?token=');
        });
    });
});

describe('POST /api/v1/portal/accept-invite', function (): void {

    it('exchanges a valid token for a Sanctum token and sets the password', function (): void {
        $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/clients/{$this->client->id}/invite-portal", [
                'email' => 'newbie@example.com',
                'name' => 'Newbie',
            ])->assertCreated();

        $url = app(ClientInvitationService::class);
        $plaintext = PortalInviteToken::query()
            ->where('user_id', User::query()->where('email', 'newbie@example.com')->value('id'))
            ->value('token_hash');

        // Re-issue an invite via the service directly so we can capture the
        // plaintext token that the controller-path doesn't expose.
        PortalInviteToken::query()->delete();
        User::where('email', 'newbie@example.com')->delete();

        $client2 = Client::factory()->create(['tenant_id' => $this->tenant->id]);
        app()->instance('tenant.id', $this->tenant->id);
        $invite = $url->inviteClientUser($client2, 'real@example.com', 'Real');

        $response = $this->postJson('/api/v1/portal/accept-invite', [
            'token' => $invite['invite_token'],
            'password' => '563de2466eb3a6fa682f6b58.X9!a',
            'password_confirmation' => '563de2466eb3a6fa682f6b58.X9!a',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.user.email', 'real@example.com')
            ->assertJsonPath('data.user.role', 'client')
            ->assertJsonStructure(['data' => ['token']]);

        expect(Hash::check('563de2466eb3a6fa682f6b58.X9!a', $invite['user']->fresh()->password))
            ->toBeTrue();

        $this->assertDatabaseHas('portal_invite_tokens', [
            'user_id' => $invite['user']->id,
        ]);
        expect(PortalInviteToken::query()->first()->used_at)
            ->not->toBeNull();
    });

    it('rejects an invalid token', function (): void {
        $this->postJson('/api/v1/portal/accept-invite', [
            'token' => 'not-a-real-token',
            'password' => '563de2466eb3a6fa682f6b58.X9!a',
            'password_confirmation' => '563de2466eb3a6fa682f6b58.X9!a',
        ])->assertUnprocessable()->assertJsonValidationErrors(['token']);
    });

    it('rejects a used token', function (): void {
        $client2 = Client::factory()->create(['tenant_id' => $this->tenant->id]);
        app()->instance('tenant.id', $this->tenant->id);
        $invite = app(ClientInvitationService::class)
            ->inviteClientUser($client2, 'one-shot@example.com', 'One Shot');

        // First use succeeds
        $this->postJson('/api/v1/portal/accept-invite', [
            'token' => $invite['invite_token'],
            'password' => '563de2466eb3a6fa682f6b58.X9!a',
            'password_confirmation' => '563de2466eb3a6fa682f6b58.X9!a',
        ])->assertOk();

        // Replay fails
        $this->postJson('/api/v1/portal/accept-invite', [
            'token' => $invite['invite_token'],
            'password' => '7a39bc1f02d8e459d7f1c620.M5!b',
            'password_confirmation' => '7a39bc1f02d8e459d7f1c620.M5!b',
        ])->assertUnprocessable()->assertJsonValidationErrors(['token']);
    });

    it('rejects an expired token', function (): void {
        $client2 = Client::factory()->create(['tenant_id' => $this->tenant->id]);
        app()->instance('tenant.id', $this->tenant->id);
        $invite = app(ClientInvitationService::class)
            ->inviteClientUser($client2, 'stale@example.com', 'Stale');

        PortalInviteToken::query()
            ->where('user_id', $invite['user']->id)
            ->update(['expires_at' => now()->subMinute()]);

        $this->postJson('/api/v1/portal/accept-invite', [
            'token' => $invite['invite_token'],
            'password' => 'a91c4d8f7e3b62059d.Q8!z',
            'password_confirmation' => 'a91c4d8f7e3b62059d.Q8!z',
        ])->assertUnprocessable()->assertJsonValidationErrors(['token']);
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

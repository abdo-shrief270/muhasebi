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
        \Illuminate\Support\Facades\Mail::fake();

        $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/clients/{$this->client->id}/invite-portal", [
                'email' => 'mailed@example.com',
                'name' => 'Mailed Client',
            ])
            ->assertCreated();

        \Illuminate\Support\Facades\Mail::assertQueued(\App\Mail\ClientPortalInviteMail::class, function ($mail) {
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

        $url = app(\App\Domain\ClientPortal\Services\ClientInvitationService::class);
        $plaintext = \App\Domain\ClientPortal\Models\PortalInviteToken::query()
            ->where('user_id', User::query()->where('email', 'newbie@example.com')->value('id'))
            ->value('token_hash');

        // Re-issue an invite via the service directly so we can capture the
        // plaintext token that the controller-path doesn't expose.
        \App\Domain\ClientPortal\Models\PortalInviteToken::query()->delete();
        \App\Models\User::where('email', 'newbie@example.com')->delete();

        $client2 = Client::factory()->create(['tenant_id' => $this->tenant->id]);
        app()->instance('tenant.id', $this->tenant->id);
        $invite = $url->inviteClientUser($client2, 'real@example.com', 'Real');

        $response = $this->postJson('/api/v1/portal/accept-invite', [
            'token' => $invite['invite_token'],
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.user.email', 'real@example.com')
            ->assertJsonPath('data.user.role', 'client')
            ->assertJsonStructure(['data' => ['token']]);

        expect(\Illuminate\Support\Facades\Hash::check('NewPassword123!', $invite['user']->fresh()->password))
            ->toBeTrue();

        $this->assertDatabaseHas('portal_invite_tokens', [
            'user_id' => $invite['user']->id,
        ]);
        expect(\App\Domain\ClientPortal\Models\PortalInviteToken::query()->first()->used_at)
            ->not->toBeNull();
    });

    it('rejects an invalid token', function (): void {
        $this->postJson('/api/v1/portal/accept-invite', [
            'token' => 'not-a-real-token',
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ])->assertUnprocessable()->assertJsonValidationErrors(['token']);
    });

    it('rejects a used token', function (): void {
        $client2 = Client::factory()->create(['tenant_id' => $this->tenant->id]);
        app()->instance('tenant.id', $this->tenant->id);
        $invite = app(\App\Domain\ClientPortal\Services\ClientInvitationService::class)
            ->inviteClientUser($client2, 'one-shot@example.com', 'One Shot');

        // First use succeeds
        $this->postJson('/api/v1/portal/accept-invite', [
            'token' => $invite['invite_token'],
            'password' => 'FirstPass123!',
            'password_confirmation' => 'FirstPass123!',
        ])->assertOk();

        // Replay fails
        $this->postJson('/api/v1/portal/accept-invite', [
            'token' => $invite['invite_token'],
            'password' => 'SecondPass123!',
            'password_confirmation' => 'SecondPass123!',
        ])->assertUnprocessable()->assertJsonValidationErrors(['token']);
    });

    it('rejects an expired token', function (): void {
        $client2 = Client::factory()->create(['tenant_id' => $this->tenant->id]);
        app()->instance('tenant.id', $this->tenant->id);
        $invite = app(\App\Domain\ClientPortal\Services\ClientInvitationService::class)
            ->inviteClientUser($client2, 'stale@example.com', 'Stale');

        \App\Domain\ClientPortal\Models\PortalInviteToken::query()
            ->where('user_id', $invite['user']->id)
            ->update(['expires_at' => now()->subMinute()]);

        $this->postJson('/api/v1/portal/accept-invite', [
            'token' => $invite['invite_token'],
            'password' => 'Whatever123!',
            'password_confirmation' => 'Whatever123!',
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

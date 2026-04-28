<?php

declare(strict_types=1);

namespace App\Domain\ClientPortal\Services;

use App\Domain\Client\Models\Client;
use App\Domain\ClientPortal\Models\PortalInviteToken;
use App\Domain\Shared\Enums\UserRole;
use App\Domain\Tenant\Models\Tenant;
use App\Mail\ClientPortalInviteMail;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ClientInvitationService
{
    private const INVITE_TTL_DAYS = 7;

    /**
     * Invite a client to the portal. Creates a user linked to the Client,
     * issues a signed invite token (7-day TTL), and sends a welcome email
     * carrying the accept-invite URL. The token plaintext is returned so
     * tests / inspectors can grab it — in production the email is the only
     * channel that ever sees it.
     *
     * @return array{user: User, invite_token: string, invite_url: string}
     *
     * @throws ValidationException
     */
    public function inviteClientUser(Client $client, string $email, string $name): array
    {
        $tenantId = (int) app('tenant.id');

        $exists = User::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('email', $email)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'email' => [
                    'A user with this email already exists.',
                    'يوجد مستخدم بهذا البريد الإلكتروني بالفعل.',
                ],
            ]);
        }

        $user = User::query()->create([
            'tenant_id' => $tenantId,
            'client_id' => $client->id,
            'name' => $name,
            'email' => $email,
            'password' => Hash::make(Str::random(32)),
            'role' => UserRole::Client,
            'locale' => 'ar',
            'is_active' => true,
        ]);

        $plaintext = Str::random(64);
        PortalInviteToken::query()->create([
            'user_id' => $user->id,
            'token_hash' => PortalInviteToken::hash($plaintext),
            'expires_at' => now()->addDays(self::INVITE_TTL_DAYS),
        ]);

        $inviteUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/')
            .'/portal/accept-invite?token='.$plaintext;

        $tenant = Tenant::query()->find($tenantId);
        $tenantName = $tenant?->name ?? 'محاسبي';

        // Send the dedicated invite email carrying the magic-link — the
        // generic welcome flow assumes an already-authenticated user
        // landing on /onboarding, which is a dead end for invited clients.
        Mail::to($user->email)->send(new ClientPortalInviteMail(
            userName: $user->name,
            tenantName: $tenantName,
            actionUrl: $inviteUrl,
        ));

        return [
            'user' => $user,
            'invite_token' => $plaintext,
            'invite_url' => $inviteUrl,
        ];
    }

    /**
     * Portal users attached to a client, with their pending-invite state.
     * Each row carries:
     *   - the User model (so the SPA can render name, email, last_login_at)
     *   - status: 'active' | 'pending' (pending = at least one unused, unexpired token)
     *   - invite_expires_at: ISO timestamp of the latest active invite (if any)
     *
     * @return list<array{user: User, status: string, invite_expires_at: ?string}>
     */
    public function listPortalUsers(Client $client): array
    {
        $users = User::query()
            ->where('client_id', $client->id)
            ->where('role', UserRole::Client)
            ->withTrashed()
            ->orderBy('created_at')
            ->get();

        if ($users->isEmpty()) {
            return [];
        }

        // Most-recent unused, unexpired invite per user.
        $pending = PortalInviteToken::query()
            ->whereIn('user_id', $users->pluck('id'))
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('user_id');

        return $users->map(function (User $user) use ($pending): array {
            $latestInvite = $pending->get($user->id)?->first();
            // Pending = the user has never logged in (last_login_at is null)
            // AND there's still a live invite. After they accept, the invite
            // is marked used → status flips to active automatically.
            $isPending = $latestInvite !== null && $user->last_login_at === null;

            return [
                'user' => $user,
                'status' => $isPending ? 'pending' : 'active',
                'invite_expires_at' => $latestInvite?->expires_at?->toIso8601String(),
            ];
        })->all();
    }

    /**
     * Revoke a client's portal user. Deactivates the user, deletes any
     * outstanding invite tokens so the magic-link can't be used, and
     * revokes all of their Sanctum tokens so existing sessions die.
     *
     * Soft-delete instead of hard so the audit trail (activity log,
     * messages they sent) stays intact.
     *
     * @throws ValidationException
     */
    public function revokePortalUser(Client $client, User $user): void
    {
        $this->assertOwnsClient($client, $user);

        // Burn outstanding invites so an in-flight email can't be redeemed.
        PortalInviteToken::query()
            ->where('user_id', $user->id)
            ->whereNull('used_at')
            ->delete();

        // Revoke any active Sanctum tokens (admin clicked Revoke, kill them now).
        $user->tokens()->delete();

        $user->update(['is_active' => false]);
        $user->delete();
    }

    /**
     * Reissue an invite to an existing portal user. Used when:
     *   - the original invite expired before they clicked it
     *   - the email got lost / went to spam
     * Burns any outstanding token first so only one valid magic-link exists
     * at a time per user.
     *
     * @return array{user: User, invite_token: string, invite_url: string}
     *
     * @throws ValidationException
     */
    public function resendInvite(Client $client, User $user): array
    {
        $this->assertOwnsClient($client, $user);

        if ($user->last_login_at !== null) {
            throw ValidationException::withMessages([
                'user' => [
                    'This user has already logged in. Use the password-reset flow instead.',
                    'هذا المستخدم سجل دخوله بالفعل. استخدم تدفق استعادة كلمة المرور بدلاً من ذلك.',
                ],
            ]);
        }

        PortalInviteToken::query()
            ->where('user_id', $user->id)
            ->whereNull('used_at')
            ->delete();

        $plaintext = Str::random(64);
        PortalInviteToken::query()->create([
            'user_id' => $user->id,
            'token_hash' => PortalInviteToken::hash($plaintext),
            'expires_at' => now()->addDays(self::INVITE_TTL_DAYS),
        ]);

        $inviteUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/')
            .'/portal/accept-invite?token='.$plaintext;

        $tenant = Tenant::query()->find($user->tenant_id);
        $tenantName = $tenant?->name ?? 'محاسبي';

        Mail::to($user->email)->send(new ClientPortalInviteMail(
            userName: $user->name,
            tenantName: $tenantName,
            actionUrl: $inviteUrl,
        ));

        return [
            'user' => $user,
            'invite_token' => $plaintext,
            'invite_url' => $inviteUrl,
        ];
    }

    /**
     * Guard that the user being acted on actually belongs to this client.
     * Prevents cross-client revoke / resend by ID-tampering.
     *
     * @throws ValidationException
     */
    private function assertOwnsClient(Client $client, User $user): void
    {
        if ((int) $user->client_id !== (int) $client->id) {
            throw ValidationException::withMessages([
                'user' => ['User does not belong to this client.'],
            ]);
        }
    }

    /**
     * Accept a portal invite. Verifies the token, sets the user's password,
     * marks the token used, and returns a fresh Sanctum token.
     *
     * @return array{user: User, token: string}
     *
     * @throws ValidationException
     */
    public function acceptInvite(string $plaintext, string $password): array
    {
        $invite = PortalInviteToken::query()
            ->where('token_hash', PortalInviteToken::hash($plaintext))
            ->first();

        if (! $invite || ! $invite->isValid()) {
            throw ValidationException::withMessages([
                'token' => [
                    'Invite is invalid or has expired.',
                    'الدعوة غير صالحة أو منتهية الصلاحية.',
                ],
            ]);
        }

        $user = User::withoutGlobalScopes()->find($invite->user_id);
        if (! $user) {
            throw ValidationException::withMessages([
                'token' => ['Invite target user no longer exists.'],
            ]);
        }

        $user->password = Hash::make($password);
        $user->save();

        $invite->used_at = now();
        $invite->save();

        return [
            'user' => $user,
            'token' => $user->createToken('portal-invite')->plainTextToken,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\ClientPortal\Services;

use App\Domain\Client\Models\Client;
use App\Domain\ClientPortal\Models\PortalInviteToken;
use App\Domain\Shared\Enums\UserRole;
use App\Domain\Tenant\Models\Tenant;
use App\Mail\ClientPortalInviteMail;
use App\Models\User;
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
     * @throws ValidationException
     * @return array{user: User, invite_token: string, invite_url: string}
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
     * Accept a portal invite. Verifies the token, sets the user's password,
     * marks the token used, and returns a fresh Sanctum token.
     *
     * @throws ValidationException
     * @return array{user: User, token: string}
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

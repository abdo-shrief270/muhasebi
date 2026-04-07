<?php

declare(strict_types=1);

namespace App\Domain\ClientPortal\Services;

use App\Domain\Client\Models\Client;
use App\Domain\Notification\Services\NotificationService;
use App\Domain\Shared\Enums\UserRole;
use App\Domain\Tenant\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ClientInvitationService
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    /**
     * Invite a client to the portal by creating a user account linked to the Client entity.
     *
     * @throws ValidationException
     */
    public function inviteClientUser(Client $client, string $email, string $name): User
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
            'password' => Hash::make(Str::random(16)),
            'role' => UserRole::Client,
            'locale' => 'ar',
            'is_active' => true,
        ]);

        $tenant = Tenant::query()->find($tenantId);
        $this->notificationService->sendWelcome($user->id, $tenant?->name ?? 'محاسبي');

        return $user;
    }
}

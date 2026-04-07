<?php

declare(strict_types=1);

namespace App\Domain\Auth\Services;

use App\Domain\Shared\Enums\TenantStatus;
use App\Domain\Shared\Enums\UserRole;
use App\Domain\Tenant\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthService
{
    /**
     * Register a new tenant with its admin user.
     *
     * @param  array<string, mixed>  $data
     * @return array{user: User, tenant: Tenant, token: string}
     */
    public function register(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $tenant = Tenant::query()->create([
                'name' => $data['tenant_name'],
                'slug' => $data['tenant_slug'],
                'status' => TenantStatus::Trial,
                'trial_ends_at' => now()->addDays(14),
                'settings' => [
                    'locale' => 'ar',
                    'timezone' => 'Africa/Cairo',
                    'currency' => 'EGP',
                    'fiscal_year_start' => '01-01',
                ],
            ]);

            $user = User::query()->create([
                'tenant_id' => $tenant->id,
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'phone' => $data['phone'] ?? null,
                'role' => UserRole::Admin,
                'locale' => 'ar',
            ]);

            // Assign Spatie role
            try {
                $user->assignRole('admin');
            } catch (\Throwable) { /* role may not exist yet */
            }

            $token = $user->createToken('auth-token')->plainTextToken;

            return compact('user', 'tenant', 'token');
        });
    }

    /**
     * Authenticate a user and return a Sanctum token.
     *
     * @param  array<string, mixed>  $credentials
     * @return array{user: User, token: string}
     *
     * @throws ValidationException
     */
    public function login(array $credentials): array
    {
        $user = User::query()
            ->with('tenant')
            ->withoutGlobalScope('tenant')
            ->where('email', $credentials['email'])
            ->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['Your account has been deactivated.'],
            ]);
        }

        // Check tenant accessibility (skip for super admins)
        if ($user->tenant_id && $user->tenant && ! $user->tenant->isAccessible()) {
            throw ValidationException::withMessages([
                'email' => ['Your organization account is currently '.$user->tenant->status->label().'.'],
            ]);
        }

        $user->recordLogin();

        $token = $user->createToken('auth-token')->plainTextToken;

        return compact('user', 'token');
    }

    /**
     * Revoke the current user's token.
     */
    public function logout(User $user): void
    {
        $user->currentAccessToken()->delete();
    }
}

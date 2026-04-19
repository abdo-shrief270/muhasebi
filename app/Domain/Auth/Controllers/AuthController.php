<?php

declare(strict_types=1);

namespace App\Domain\Auth\Controllers;

use App\Domain\Auth\Requests\LoginRequest;
use App\Domain\Auth\Requests\RegisterRequest;
use App\Domain\Auth\Services\AuthService;
use App\Domain\Auth\Services\PermissionService;
use App\Domain\Shared\Services\FeatureFlagService;
use App\Domain\Subscription\Models\Subscription;
use App\Domain\Tenant\Models\Tenant;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
    ) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $result = $this->authService->register($request->validated());

        return response()->json([
            'message' => 'Registration successful.',
            'data' => [
                'user' => [
                    'id' => $result['user']->id,
                    'name' => $result['user']->name,
                    'email' => $result['user']->email,
                    'role' => $result['user']->role->value,
                ],
                'tenant' => [
                    'id' => $result['tenant']->id,
                    'name' => $result['tenant']->name,
                    'slug' => $result['tenant']->slug,
                    'status' => $result['tenant']->status->value,
                    'trial_ends_at' => $result['tenant']->trial_ends_at?->toISOString(),
                ],
                'token' => $result['token'],
            ],
        ], Response::HTTP_CREATED);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->login($request->validated());
        $user = $result['user'];

        // True when the user is subject to 2FA enforcement (admin / super-admin)
        // but hasn't enabled it yet. Frontend should redirect to /v1/2fa/enable.
        // Matches the gating condition in Enforce2fa middleware so the contract
        // and the downstream 403 stay in sync.
        $requires2fa = ($user->isSuperAdmin() || $user->isAdmin()) && ! $user->two_factor_enabled;

        return response()->json([
            'message' => 'Login successful.',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role->value,
                    'tenant_id' => $user->tenant_id,
                ],
                'token' => $result['token'],
                'requires_2fa' => $requires2fa,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request->user());

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenant = $user->tenant_id ? Tenant::find($user->tenant_id) : null;

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'role' => $user->role->value,
                'locale' => $user->locale,
                'tenant_id' => $user->tenant_id,
                'timezone' => $user->timezone,
                'is_active' => $user->is_active,
                'email_verified_at' => $user->email_verified_at?->toISOString(),
                'last_login_at' => $user->last_login_at?->toISOString(),
                'permissions' => PermissionService::getUserPermissions($user),
                'spatie_roles' => $user->getRoleNames(),
                'tenant' => $tenant ? [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'email' => $tenant->email,
                    'phone' => $tenant->phone,
                    'logo_path' => $tenant->logo_path,
                    'tagline' => $tenant->tagline,
                    'primary_color' => $tenant->primary_color,
                    'secondary_color' => $tenant->secondary_color,
                    'city' => $tenant->city,
                    'features' => $this->tenantFeatures($tenant->id),
                ] : null,
            ],
        ]);
    }

    /**
     * Resolve the merged feature-flag map for a tenant, including per-tenant
     * overrides layered on top of plan-bundled flags. Result is cached for
     * 5 minutes inside FeatureFlagService::getAllForTenant, so repeated
     * /me calls from the same tenant don't thrash the DB.
     *
     * @return array<string, bool>
     */
    private function tenantFeatures(int $tenantId): array
    {
        $planId = Subscription::query()
            ->where('tenant_id', $tenantId)
            ->active()
            ->value('plan_id');

        return FeatureFlagService::getAllForTenant($tenantId, $planId ? (int) $planId : null);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'locale' => ['sometimes', 'string', 'in:ar,en'],
            'timezone' => ['nullable', 'string', 'max:50'],
        ]);

        $user = $request->user();
        $user->update($request->only(['name', 'phone', 'locale', 'timezone']));

        return response()->json([
            'message' => 'Profile updated successfully.',
            'data' => ['name' => $user->name, 'phone' => $user->phone, 'locale' => $user->locale, 'timezone' => $user->timezone],
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $request->user();

        if (! Hash::check($request->input('current_password'), $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => [
                    'The current password is incorrect.',
                    'كلمة المرور الحالية غير صحيحة.',
                ],
            ]);
        }

        $user->update(['password' => Hash::make($request->input('password'))]);

        return response()->json(['message' => 'Password changed successfully.']);
    }
}

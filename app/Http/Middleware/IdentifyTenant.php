<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenant\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the current tenant from the request header, subdomain, or query param.
 * X-Tenant accepts either a numeric ID or a slug.
 */
class IdentifyTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->resolveTenant($request);

        if (! $tenant) {
            return response()->json([
                'message' => 'Tenant not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        if (! $tenant->isAccessible()) {
            return response()->json([
                'message' => 'Tenant account is not accessible. Status: '.$tenant->status->label(),
            ], Response::HTTP_FORBIDDEN);
        }

        // Verify authenticated user belongs to this tenant (skip for super admins)
        $user = $request->user();
        if ($user && ! $user->isSuperAdmin() && $user->tenant_id !== $tenant->id) {
            return response()->json([
                'message' => 'You do not have access to this tenant.',
            ], Response::HTTP_FORBIDDEN);
        }

        // Bind tenant into the container
        app()->instance('tenant', $tenant);
        app()->instance('tenant.id', $tenant->id);

        return $next($request);
    }

    private function resolveTenant(Request $request): ?Tenant
    {
        // Priority 1: X-Tenant header (accepts ID or slug)
        if ($value = $request->header('X-Tenant')) {
            return $this->findByIdOrSlug($value);
        }

        // Priority 2: Subdomain
        $host = $request->getHost();
        $parts = explode('.', $host);

        if (count($parts) >= 3) {
            $subdomain = $parts[0];

            return Tenant::query()->where('slug', $subdomain)->first();
        }

        // Priority 3: Route parameter
        if ($value = $request->route('tenant')) {
            return $this->findByIdOrSlug((string) $value);
        }

        return null;
    }

    /**
     * Find a tenant by numeric ID or string slug.
     */
    private function findByIdOrSlug(string $value): ?Tenant
    {
        if (is_numeric($value)) {
            return Tenant::query()->find((int) $value);
        }

        return Tenant::query()->where('slug', $value)->first();
    }
}

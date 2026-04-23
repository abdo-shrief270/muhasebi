<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenant\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the current tenant from the request header, subdomain, or route param,
 * falling back to the authenticated user's home tenant.
 *
 * Explicit sources (X-Tenant header, non-reserved subdomain, {tenant} route param)
 * must resolve or the request 404s — masking a bad explicit tenant by silently
 * falling back to the caller's home tenant would hide errors and enable
 * cross-tenant data leaks. Only when no explicit source is present do we fall
 * back to the authenticated user's home tenant. Reserved platform subdomains
 * (api, www, admin, ...) are treated as "no source" so api.muhasebi.com still
 * resolves the caller's home tenant.
 */
class IdentifyTenant
{
    /**
     * Subdomain labels that are reserved for the platform itself and must never
     * be treated as tenant slugs. Extend via config('tenant.reserved_subdomains')
     * if more environments are added.
     */
    private const RESERVED_SUBDOMAINS = ['www', 'api', 'admin', 'app', 'mail', 'static', 'cdn'];

    public function handle(Request $request, Closure $next): Response
    {
        // Priority 1: explicit X-Tenant header (accepts numeric id or slug).
        if ($value = $request->header('X-Tenant')) {
            $tenant = $this->findByIdOrSlug((string) $value);

            return $tenant
                ? $this->authorize($request, $next, $tenant)
                : $this->notFound();
        }

        // Priority 2: subdomain, skipping reserved platform subdomains.
        $parts = explode('.', $request->getHost());
        if (count($parts) >= 3) {
            $subdomain = strtolower($parts[0]);
            $reserved = array_map('strtolower', (array) config('tenant.reserved_subdomains', self::RESERVED_SUBDOMAINS));
            if (! in_array($subdomain, $reserved, true)) {
                $tenant = Tenant::query()->where('slug', $subdomain)->first();

                return $tenant
                    ? $this->authorize($request, $next, $tenant)
                    : $this->notFound();
            }
        }

        // Priority 3: route parameter {tenant}.
        if ($value = $request->route('tenant')) {
            $tenant = $this->findByIdOrSlug((string) $value);

            return $tenant
                ? $this->authorize($request, $next, $tenant)
                : $this->notFound();
        }

        // Priority 4: authenticated user's own tenant. Every tenant user has
        // exactly one tenant, so when no explicit tenant is supplied, fall back
        // to the caller's home tenant. X-Tenant is only needed for super-admin
        // impersonation or cross-tenant admin operations.
        $user = $request->user();
        if ($user && $user->tenant_id) {
            $tenant = Tenant::query()->find($user->tenant_id);
            if ($tenant) {
                return $this->authorize($request, $next, $tenant);
            }
        }

        return $this->notFound();
    }

    private function authorize(Request $request, Closure $next, Tenant $tenant): Response
    {
        if (! $tenant->isAccessible()) {
            return response()->json([
                'message' => 'Tenant account is not accessible. Status: '.$tenant->status->label(),
            ], Response::HTTP_FORBIDDEN);
        }

        $user = $request->user();
        if ($user && ! $user->isSuperAdmin() && $user->tenant_id !== $tenant->id) {
            return response()->json([
                'message' => 'You do not have access to this tenant.',
            ], Response::HTTP_FORBIDDEN);
        }

        // Audit cross-tenant access by SuperAdmins so impersonation and admin
        // operations against tenant-scoped routes leave a trail — LogAdminActivity
        // only covers /admin/* routes and misses tenant-API access via X-Tenant.
        if ($user && $user->isSuperAdmin() && $user->tenant_id !== $tenant->id) {
            Log::channel('stack')->notice('SuperAdmin cross-tenant access', [
                'super_admin_id' => $user->id,
                'super_admin_email' => $user->email,
                'tenant_id' => $tenant->id,
                'tenant_slug' => $tenant->slug,
                'method' => $request->method(),
                'path' => $request->path(),
                'ip' => $request->ip(),
            ]);
        }

        app()->instance('tenant', $tenant);
        app()->instance('tenant.id', $tenant->id);

        return $next($request);
    }

    private function notFound(): Response
    {
        return response()->json(['message' => 'Tenant not found.'], Response::HTTP_NOT_FOUND);
    }

    private function findByIdOrSlug(string $value): ?Tenant
    {
        if (is_numeric($value)) {
            return Tenant::query()->find((int) $value);
        }

        return Tenant::query()->where('slug', $value)->first();
    }
}

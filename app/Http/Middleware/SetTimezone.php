<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetTimezone
{
    public function handle(Request $request, Closure $next): Response
    {
        $timezone = $this->resolveTimezone($request);

        date_default_timezone_set($timezone);
        app()->instance('timezone', $timezone);

        return $next($request);
    }

    public function terminate(): void
    {
        date_default_timezone_set('UTC');
    }

    private function resolveTimezone(Request $request): string
    {
        $user = $request->user();

        if ($user?->timezone && in_array($user->timezone, \DateTimeZone::listIdentifiers(), true)) {
            return $user->timezone;
        }

        $tenant = app()->bound('tenant') ? app('tenant') : null;

        if ($tenant && ($tz = $tenant->settings['timezone'] ?? null) && in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
            return $tz;
        }

        return 'Africa/Cairo';
    }
}

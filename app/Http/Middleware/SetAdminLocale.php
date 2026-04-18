<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forces the admin panel into Arabic locale by default, overridable per-user via
 * `users.locale` (which is set by users on their own profile). Filament ships
 * Arabic translations for its chrome (buttons, pagination, modals), and
 * lang/ar/admin.php covers our resource labels and nav groups. Arabic locale
 * also flips the HTML direction to RTL via Filament's layout/direction key.
 */
class SetAdminLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $locale = $user?->locale ?? 'ar';

        if (in_array($locale, ['ar', 'en'], true)) {
            App::setLocale($locale);
        }

        return $next($request);
    }
}

<?php

declare(strict_types=1);

use App\Http\Middleware\CheckFeature;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\CheckLimit;
use App\Http\Middleware\CheckSubscription;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\ClientPortalMiddleware;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\IdentifyTenant;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SetTimezone;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__ . '/../routes/api.php',
        web: __DIR__ . '/../routes/web.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Prepend locale detection so errors are in the correct language
        $middleware->prependToGroup('api', [
            \App\Http\Middleware\DetectLocale::class,
        ]);

        // Append global middleware to all API responses
        $middleware->appendToGroup('api', [
            \App\Http\Middleware\SecurityHeaders::class,
            \App\Http\Middleware\CompressResponse::class,
            \App\Http\Middleware\ApiVersion::class,
            \App\Http\Middleware\LogApiRequest::class,
        ]);

        $middleware->alias([
            'tenant' => IdentifyTenant::class,
            'super_admin' => EnsureSuperAdmin::class,
            'active' => EnsureActiveUser::class,
            'subscription' => CheckSubscription::class,
            'feature' => CheckFeature::class,
            'limit' => CheckLimit::class,
            'client_portal' => ClientPortalMiddleware::class,
            'set_timezone' => SetTimezone::class,
            'set_locale' => SetLocale::class,
            'permission' => CheckPermission::class,
            'cache.public' => \App\Http\Middleware\CachePublicResponse::class,
            'meter.usage' => \App\Http\Middleware\MeterApiUsage::class,
            'admin.ip' => \App\Http\Middleware\AdminIpWhitelist::class,
            'idempotent' => \App\Http\Middleware\IdempotencyKey::class,
            'no-duplicate' => \App\Http\Middleware\PreventDuplicateRequests::class,
            'enforce.2fa' => \App\Http\Middleware\Enforce2fa::class,
        ]);

        $middleware->priority([
            \App\Http\Middleware\IdentifyTenant::class,
            \App\Http\Middleware\SetTimezone::class,
            \App\Http\Middleware\SetLocale::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);

        // Token-based API auth (no CSRF needed)
        // $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Standardize all API error responses
        $exceptions->renderable(function (\Illuminate\Validation\ValidationException $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return \App\Http\ApiResponse::validationError(
                    errors: $e->errors(),
                    message: $e->getMessage(),
                );
            }
        });

        $exceptions->renderable(function (\Illuminate\Auth\AuthenticationException $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return \App\Http\ApiResponse::unauthorized();
            }
        });

        $exceptions->renderable(function (\Illuminate\Auth\Access\AuthorizationException $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return \App\Http\ApiResponse::forbidden($e->getMessage() ?: null);
            }
        });

        $exceptions->renderable(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return \App\Http\ApiResponse::notFound();
            }
        });

        $exceptions->renderable(function (\Illuminate\Database\Eloquent\ModelNotFoundException $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                $model = class_basename($e->getModel());
                return \App\Http\ApiResponse::notFound(__('messages.error.model_not_found', ['model' => $model]));
            }
        });

        $exceptions->renderable(function (\Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return \App\Http\ApiResponse::tooManyRequests();
            }
        });

        $exceptions->renderable(function (\Symfony\Component\HttpKernel\Exception\HttpException $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return \App\Http\ApiResponse::error(
                    code: 'http_error',
                    message: $e->getMessage() ?: __('messages.error.server_error'),
                    status: $e->getStatusCode(),
                );
            }
        });

        $exceptions->renderable(function (\Throwable $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                $message = app()->isProduction()
                    ? __('messages.error.server_error')
                    : $e->getMessage();

                return \App\Http\ApiResponse::serverError($message);
            }
        });
    })
    ->create();

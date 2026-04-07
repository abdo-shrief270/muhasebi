<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class HealthCheckController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [];
        $healthy = true;

        // Database
        try {
            DB::connection()->getPdo();
            $checks['database'] = 'ok';
        } catch (\Throwable $e) {
            $checks['database'] = 'error';
            $healthy = false;
        }

        // Cache
        try {
            Cache::put('health_check', true, 10);
            $checks['cache'] = Cache::get('health_check') ? 'ok' : 'error';
            Cache::forget('health_check');
        } catch (\Throwable $e) {
            $checks['cache'] = 'error';
            $healthy = false;
        }

        // Storage
        try {
            $testPath = storage_path('app/health_check.tmp');
            file_put_contents($testPath, 'ok');
            $checks['storage'] = file_get_contents($testPath) === 'ok' ? 'ok' : 'error';
            @unlink($testPath);
        } catch (\Throwable $e) {
            $checks['storage'] = 'error';
            $healthy = false;
        }

        $status = $healthy ? 200 : 503;

        return response()->json([
            'status' => $healthy ? 'healthy' : 'unhealthy',
            'checks' => $checks,
            'version' => config('app.version', '2.4.0'),
            'timestamp' => now()->toISOString(),
        ], $status);
    }
}

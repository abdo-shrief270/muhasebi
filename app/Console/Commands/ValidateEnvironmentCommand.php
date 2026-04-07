<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ValidateEnvironmentCommand extends Command
{
    protected $signature = 'env:validate';
    protected $description = 'Validate required environment variables for production';

    private const REQUIRED_VARS = [
        'APP_KEY',
        'APP_URL',
        'DB_HOST',
        'DB_DATABASE',
        'DB_USERNAME',
    ];

    private const PRODUCTION_VARS = [
        'CORS_ALLOWED_ORIGINS',
        'ADMIN_IP_WHITELIST',
        'SANCTUM_EXPIRATION',
    ];

    public function handle(): int
    {
        $missing = [];

        foreach (self::REQUIRED_VARS as $var) {
            if (empty(env($var))) {
                $missing[] = $var;
            }
        }

        if (app()->isProduction()) {
            foreach (self::PRODUCTION_VARS as $var) {
                if (empty(env($var))) {
                    $missing[] = "{$var} (production-only)";
                }
            }
        }

        if (!empty($missing)) {
            $this->error('Missing required environment variables:');
            foreach ($missing as $var) {
                $this->line("  - {$var}");
            }
            return 1;
        }

        $this->info('All required environment variables are set.');
        return 0;
    }
}

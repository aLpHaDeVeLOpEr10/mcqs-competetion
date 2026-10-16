<?php

namespace App\Console\Commands;

use App\Services\Compliance\LaunchGate;
use Illuminate\Console\Command;

/**
 * Read-only deployment checklist for security-relevant configuration. Prints only
 * pass/fail per setting, never a configured value (no secrets in output or logs).
 * Exits non-zero when a production deployment has a failing check.
 */
class SecurityCheck extends Command
{
    protected $signature = 'security:check {--production : Evaluate as if APP_ENV were production}';

    protected $description = 'Check security-relevant configuration (never prints secret values)';

    public function handle(): int
    {
        $production = $this->option('production') || app()->isProduction();
        $https = str_starts_with((string) config('app.url'), 'https://');

        // [check, ok, required in production]
        $checks = [
            ['APP_KEY is set', filled(config('app.key')), true],
            ['APP_DEBUG is off', config('app.debug') === false, true],
            ['APP_URL uses https://', $https, true],
            ['SESSION_SECURE_COOKIE is true', config('session.secure') === true, true],
            ['Session cookie is HttpOnly', config('session.http_only') === true, true],
            ['Session SameSite is lax or strict', in_array(config('session.same_site'), ['lax', 'strict'], true), true],
            ['Security headers are enabled', config('competition.security.headers.enabled') === true, true],
            ['Content-Security-Policy is enforced', config('competition.security.headers.csp') === 'enforce', true],
            ['Simulated payment gateway is off', config('competition.payments.gateway') !== 'fake' && config('competition.payments.fake.enabled') !== true, true],
            ['Simulated payout gateway is off', config('competition.withdrawals.gateway') !== 'fake' && config('competition.withdrawals.fake.enabled') !== true, true],
            ['Reverb (WebSocket) uses TLS', config('broadcasting.default') !== 'reverb' || config('broadcasting.connections.reverb.options.scheme') === 'https', false],
            ['Log level is not debug', config('logging.channels.'.config('logging.default').'.level', 'debug') !== 'debug'
                || collect(config('logging.channels.'.config('logging.default').'.channels', []))->every(fn ($c) => config("logging.channels.{$c}.level", 'debug') !== 'debug'), false],
            ['Mail is not the log/array driver', ! in_array(config('mail.default'), ['log', 'array'], true), false],
            // Deployment (docs/DEPLOYMENT.md)
            ['Queue is not "sync" (workers run the engine and notices)', config('queue.default') !== 'sync', true],
            ['Cache and session stores persist (not "array")', config('cache.default') !== 'array' && config('session.driver') !== 'array', true],
            ['Reverb allowed origins are restricted (not "*")', config('broadcasting.default') !== 'reverb'
                || ! in_array('*', (array) config('reverb.apps.apps.0.allowed_origins', ['*']), true), true],
            ['Reverb listens on localhost only (behind the TLS proxy)', config('broadcasting.default') !== 'reverb'
                || in_array(config('reverb.servers.reverb.host'), ['127.0.0.1', 'localhost', '::1'], true), false],
            ['Database user is not root', config('database.connections.'.config('database.default').'.username') !== 'root', false],
            ['Real money only with a recorded compliance approval (launch gate)', app(LaunchGate::class)->blocked() === [], true],
            ['storage/ and bootstrap/cache/ are writable', is_writable(storage_path('framework')) && is_writable(storage_path('logs')) && is_writable(base_path('bootstrap/cache')), true],
        ];

        $failures = 0;
        $rows = [];
        foreach ($checks as [$label, $ok, $required]) {
            $status = $ok ? 'ok' : ($required ? 'FAIL' : 'warn');
            if (! $ok && $required) {
                $failures++;
            }
            $rows[] = [$label, $status];
        }

        $this->table(['Check', $production ? 'Status (production)' : 'Status'], $rows);

        if (! $production) {
            $this->info('Not a production environment: failures are informational.');

            return self::SUCCESS;
        }

        if ($failures > 0) {
            $this->error($failures.' required production check(s) failed.');

            return self::FAILURE;
        }

        $this->info('All required production checks passed.');

        return self::SUCCESS;
    }
}

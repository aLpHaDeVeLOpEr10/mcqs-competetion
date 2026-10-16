<?php

namespace App\Providers;

use App\Contracts\Payments\EntryPayment;
use App\Enums\Permission;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Compliance\LaunchGate;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\WalletEntryPayment;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\QueueBusy;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Entry fees are debited from the wallet inside the join transaction.
        $this->app->bind(EntryPayment::class, WalletEntryPayment::class);

        $this->app->singleton(PaymentGatewayManager::class);
        $this->app->singleton(LaunchGate::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Real money stays off unless a recorded compliance approval covers every requirement
        // (config/compliance.php). Runs before anything reads the switches.
        $this->app->make(LaunchGate::class)->enforce();

        Paginator::defaultView('partials.pagination');

        // Breached-password lookups call an external API, so only in production.
        Password::defaults(fn () => $this->app->isProduction()
            ? Password::min(10)->letters()->mixedCase()->numbers()->uncompromised()
            : Password::min(10)->letters()->mixedCase()->numbers());

        $this->defineGates();
        $this->defineRateLimiters();

        // Console commands (scheduler, reconcilers) get their own correlation id for audit and logs.
        Event::listen(CommandStarting::class, function () {
            if (! Context::has('request_id')) {
                Context::add('request_id', (string) Str::uuid());
            }
        });

        // Queue observability: every finally failed job, and queues backing up.
        Event::listen(JobFailed::class, fn (JobFailed $event) => Log::error('Queued job failed permanently (see failed_jobs; retry with queue:retry).', [
            'job' => $event->job->resolveName(),
            'queue' => $event->job->getQueue(),
            'connection' => $event->connectionName,
            'attempts' => $event->job->attempts(),
            'error' => $event->exception->getMessage(),
        ]));
        Event::listen(QueueBusy::class, fn (QueueBusy $event) => Log::warning('Queue is backing up; check that workers are running.', [
            'connection' => $event->connection,
            'queue' => $event->queue,
            'size' => $event->size,
        ]));

        // GET /up (load balancer and uptime checks) also proves the database answers;
        // an exception here turns the response into a 500.
        Event::listen(DiagnosingHealth::class, fn () => DB::select('select 1'));

        // Staff sign-ins are part of the administrative audit trail.
        Event::listen(Login::class, function (Login $event) {
            if ($event->user instanceof User && $event->user->isStaff()) {
                app(AuditLogger::class)->record('auth.staff_login', $event->user, actor: $event->user);
            }
        });
    }

    protected function defineGates(): void
    {
        // Restricted operations: competition entry and any money movement.
        Gate::define('enter-competitions', fn (User $user) => $user->canParticipate());
        Gate::define('transact', fn (User $user) => $user->canParticipate());

        // One ability per administrative permission, e.g. `can:users.view`.
        foreach (Permission::cases() as $permission) {
            Gate::define($permission->value, fn (User $user) => $user->hasPermission($permission));
        }
    }

    protected function defineRateLimiters(): void
    {
        RateLimiter::for('register', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));

        // Per IP and per target address, so one address cannot be spammed from many IPs.
        RateLimiter::for('password-reset', fn (Request $request) => [
            Limit::perMinute(5)->by($request->ip()),
            Limit::perHour(5)->by('reset:'.mb_strtolower((string) $request->input('email'))),
        ]);

        RateLimiter::for('verification', fn (Request $request) => Limit::perMinute(6)->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('account-sensitive', fn (Request $request) => Limit::perMinute(10)->by($request->user()?->id ?: $request->ip()));

        // Every limiter is NAMED: Laravel's unnamed "throttle:N,1" form keys all routes on the
        // same user or IP, so one shared counter would let state polling exhaust the
        // answer-submission budget. Named limiters each keep their own counter.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));
        RateLimiter::for('contact', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('room-entry', fn (Request $request) => Limit::perMinute(20)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('disputes', fn (Request $request) => Limit::perMinute(5)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('status-checks', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));
        // Admin and account areas: caps form submissions only (reads are unlimited here).
        RateLimiter::for('writes', fn (Request $request) => $request->isMethodSafe()
            ? Limit::none()
            : Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('provider-callbacks', fn (Request $request) => Limit::perMinute(240)->by($request->ip()));

        // Live play. Each question accepts one answer per player, so a legitimate player sends a
        // handful of answer requests per minute; 60 per room only stops scripted floods. State
        // polling (fallback when the WebSocket is down, several tabs) has its own, larger budget.
        RateLimiter::for('answers', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->id.'|'.$request->route('room')));
        RateLimiter::for('play-state', fn (Request $request) => Limit::perMinute(240)->by($request->user()?->id.'|'.$request->route('room')));
    }
}

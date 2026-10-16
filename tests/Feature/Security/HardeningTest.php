<?php

namespace Tests\Feature\Security;

use App\Enums\UserRole;
use App\Http\Middleware\SecurityHeaders;
use App\Models\User;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class HardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_responses_carry_the_security_headers(): void
    {
        config([
            'broadcasting.connections.reverb.options' => ['host' => 'ws.example.test', 'port' => 443, 'scheme' => 'https'],
            'competition.security.headers.csp_form_actions' => ['https://checkout.provider.test'],
        ]);

        $response = $this->get(route('home'))->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeaderMissing('Strict-Transport-Security');

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9]{40}'/", $csp);
        $this->assertStringNotContainsString("'unsafe-eval'", $csp);
        $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $csp);
        $this->assertStringContainsString('connect-src \'self\' wss://ws.example.test:443', $csp, 'Live updates (Reverb) stay allowed.');
        $this->assertStringContainsString('font-src \'self\' https://fonts.bunny.net', $csp);
        $this->assertStringContainsString("form-action 'self' https://checkout.provider.test", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);

        // Plain-http Reverb (development): the client may fall back from ws to wss on the same host.
        config(['broadcasting.connections.reverb.options' => ['host' => 'localhost', 'port' => 8080, 'scheme' => 'http']]);
        $this->assertStringContainsString("connect-src 'self' ws://localhost:8080 wss://localhost:8080", $this->get(route('home'))->headers->get('Content-Security-Policy'));

        // A fresh nonce per request.
        $again = $this->get(route('home'))->headers->get('Content-Security-Policy');
        $this->assertNotSame($csp, $again);
    }

    public function test_csp_can_run_in_report_only_mode_or_be_switched_off(): void
    {
        config(['competition.security.headers.csp' => 'report-only']);
        $this->get(route('home'))->assertHeaderMissing('Content-Security-Policy')
            ->assertHeader('Content-Security-Policy-Report-Only');

        config(['competition.security.headers.csp' => 'off']);
        $this->get(route('home'))->assertHeaderMissing('Content-Security-Policy')
            ->assertHeaderMissing('Content-Security-Policy-Report-Only')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_hsts_is_sent_only_in_production_over_https(): void
    {
        $this->get('https://localhost/')->assertHeaderMissing('Strict-Transport-Security');

        $this->app['env'] = 'production';
        $this->get('http://localhost/')->assertHeaderMissing('Strict-Transport-Security');
        $this->get('https://localhost/')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_the_policy_never_allows_unsafe_script_sources(): void
    {
        $policy = (new SecurityHeaders)->policy('abc');

        $this->assertStringContainsString("script-src 'self' 'nonce-abc'", $policy);
        $this->assertStringContainsString("base-uri 'self'", $policy);
        $this->assertDoesNotMatchRegularExpression("/script-src[^;]*('unsafe-inline'|'unsafe-eval'|\\*)/", $policy);
    }

    public function test_private_storage_is_not_served_over_http(): void
    {
        $this->assertFalse(config('filesystems.disks.local.serve'));
        $this->assertNull(app('router')->getRoutes()->getByName('storage.local'));
        $this->get('/storage/questions/anything.webp')->assertNotFound();
    }

    public function test_notification_links_cannot_redirect_off_site(): void
    {
        $user = User::factory()->create();
        $urls = [
            url('/account') => url('/account'),
            'https://localhost.evil.test/phish' => route('account.notifications'),
            str_replace('://', '://user@', url('/')).'evil.test' => route('account.notifications'),
            '//evil.test/' => route('account.notifications'),
            'javascript:alert(1)' => route('account.notifications'),
        ];

        foreach ($urls as $target => $expected) {
            $id = (string) Str::uuid();
            $user->notifications()->create(['id' => $id, 'type' => 'test', 'data' => ['type' => 'test', 'url' => $target]]);
            $this->actingAs($user)->get(route('account.notifications.open', $id))->assertRedirect($expected);
        }
    }

    public function test_every_admin_route_refuses_players_and_guests(): void
    {
        $player = User::factory()->create();
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn (Route $route) => str_starts_with($route->uri(), 'admin'))
            // The admin sign-in and sign-out endpoints themselves (covered by AdminSessionTest).
            ->reject(fn (Route $route) => in_array($route->uri(), ['admin/login', 'admin/logout'], true));
        $this->assertGreaterThan(50, $routes->count());

        foreach ($routes as $route) {
            $method = collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->first();
            $uri = '/'.preg_replace('/\{[^}]+\}/', '1', $route->uri());

            // Guests are sent to sign in (or get 404 when "1" does not fit the route pattern).
            $guest = $this->call($method, $uri);
            $guest->getStatusCode() === 404 ?: $guest->assertRedirect(route('admin.login'));
            $status = $this->actingAs($player)->call($method, $uri)->getStatusCode();
            $this->assertContains($status, [403, 404, 429], "{$method} {$uri} returned {$status} for a player.");
            $this->signOut();
        }
    }

    public function test_staff_are_limited_to_their_own_areas(): void
    {
        $content = User::factory()->role(UserRole::ContentManager)->create();
        $this->actingAs($content)->get(route('admin.questions.index'))->assertOk();
        foreach (['admin.users.index', 'admin.payments.index', 'admin.withdrawals.index', 'admin.audit.index', 'admin.risk.index', 'admin.reports.index'] as $name) {
            $this->actingAs($content)->get(route($name))->assertForbidden();
        }

        $admin = User::factory()->role(UserRole::Admin)->create();
        $wallet = app(WalletService::class)->walletFor(User::factory()->create());
        $this->actingAs($admin)->post(route('admin.wallets.adjust', $wallet), [])->assertForbidden();
    }

    public function test_every_limiter_is_named_so_counters_are_never_shared(): void
    {
        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'throttle:')) {
                    $this->assertDoesNotMatchRegularExpression('/^throttle:\d/', $middleware, $route->uri().' uses an unnamed (shared-counter) limit.');
                }
            }
        }
    }

    public function test_sign_in_is_rate_limited(): void
    {
        $user = User::factory()->create();
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('login'), ['email' => $user->email, 'password' => 'wrong-password']);
        }

        $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_sensitive_input_is_never_flashed_back(): void
    {
        $this->from(route('login'))->post(route('login'), ['email' => 'not-an-email', 'password' => 'secret-value-123']);

        $this->assertNull(session()->getOldInput('password'));
        $this->assertSame('not-an-email', session()->getOldInput('email'));
    }
}

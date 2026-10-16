<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser hardening headers for every web response.
 *
 * Content-Security-Policy:
 * - Scripts: same origin plus a per-request nonce. Vite adds the nonce to its tags, and the
 *   one inline script (the hosted-checkout auto-submit) carries it explicitly. No inline
 *   event handlers or eval are used anywhere.
 * - Styles allow 'unsafe-inline' because Blade components set `style` attributes (e.g. a
 *   progress bar width). Fonts come from fonts.bunny.net.
 * - connect-src adds the Reverb WebSocket origin from configuration, so live updates keep
 *   working (SECURITY_CSP_CONNECT adds origins, e.g. when browsers reach Reverb through a
 *   different public host than the server uses). In local development the Vite dev server (and its HMR socket) is allowed
 *   while it is running.
 * - form-action: self plus any provider checkout origins configured in
 *   SECURITY_CSP_FORM_ACTIONS.
 * - frame-ancestors 'none', with X-Frame-Options DENY for older browsers.
 *
 * HSTS is sent only in production and only on HTTPS requests, so a misconfigured or
 * local HTTP deployment can never lock browsers out.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $config = config('competition.security.headers');
        if (! ($config['enabled'] ?? true)) {
            return $next($request);
        }

        $nonce = Vite::useCspNonce();

        /** @var Response $response */
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        $mode = $config['csp'] ?? 'enforce';
        if ($mode !== 'off' && ! $headers->has('Content-Security-Policy')) {
            $name = $mode === 'report-only' ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
            $headers->set($name, $this->policy($nonce, $config['csp_form_actions'] ?? []));
        }

        if (app()->environment('production') && $request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age='.(int) ($config['hsts_max_age'] ?? 31536000).'; includeSubDomains');
        }

        return $response;
    }

    /**
     * @param  list<string>  $formActions
     */
    public function policy(string $nonce, array $formActions = []): string
    {
        $script = ["'self'", "'nonce-{$nonce}'"];
        $style = ["'self'", "'unsafe-inline'", 'https://fonts.bunny.net'];
        $connect = ["'self'", ...$this->reverbOrigins(), ...config('competition.security.headers.csp_connect', [])];

        if (($hot = $this->viteDevOrigin()) !== null) {
            $script[] = $hot;
            $style[] = $hot;
            $connect[] = $hot;
            $connect[] = preg_replace('/^http/', 'ws', $hot);
        }

        $directives = [
            'default-src' => ["'self'"],
            'script-src' => $script,
            'style-src' => $style,
            'font-src' => ["'self'", 'https://fonts.bunny.net'],
            'img-src' => ["'self'", 'data:', 'blob:'],
            'connect-src' => array_values(array_unique($connect)),
            'form-action' => ["'self'", ...$formActions],
            'frame-ancestors' => ["'none'"],
            'base-uri' => ["'self'"],
            'object-src' => ["'none'"],
        ];

        return implode('; ', array_map(fn ($name, $sources) => $name.' '.implode(' ', $sources), array_keys($directives), $directives));
    }

    /**
     * @return list<string>
     */
    protected function reverbOrigins(): array
    {
        $options = config('broadcasting.connections.reverb.options', []);
        $host = $options['host'] ?? null;
        if (! is_string($host) || $host === '' || preg_match('/^[A-Za-z0-9.\-]+$/', $host) !== 1) {
            return [];
        }

        $port = (int) ($options['port'] ?? 0);
        $origin = $host.($port > 0 ? ':'.$port : '');

        // TLS deployments only need wss. Over plain http the client (enabledTransports ws + wss)
        // also falls back to wss on the same host, so both are allowed.
        return ($options['scheme'] ?? 'https') === 'https'
            ? ['wss://'.$origin]
            : ['ws://'.$origin, 'wss://'.$origin];
    }

    protected function viteDevOrigin(): ?string
    {
        if (app()->environment('production') || ! Vite::isRunningHot()) {
            return null;
        }

        $url = trim((string) @file_get_contents(Vite::hotFile()));
        $parts = parse_url($url);

        return isset($parts['scheme'], $parts['host'])
            ? $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '')
            : null;
    }
}

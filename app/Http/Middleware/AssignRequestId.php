<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request a correlation id. It is stored on audit records, added
 * to every log line (Laravel Context), carried into queued jobs dispatched
 * by the request, and returned in the X-Request-Id response header. A
 * well-formed incoming X-Request-Id (e.g. from a load balancer) is reused.
 */
class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->headers->get('X-Request-Id');
        $id = Str::isUuid($incoming) ? strtolower($incoming) : (string) Str::uuid();

        Context::add('request_id', $id);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}

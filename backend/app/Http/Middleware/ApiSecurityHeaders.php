<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hardening headers for API responses (JSON, exports, document downloads). Responses hold financial and
 * commercial data, so they must not be stored by shared caches or sniffed into another content type.
 * Headers a route already set (e.g. the document download's own Cache-Control) are kept.
 */
class ApiSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        return self::apply($next($request));
    }

    /** Also called by ApiExceptionRenderer: exceptions are rendered outside the route pipeline, so this middleware never sees those responses. */
    public static function apply(Response $response): Response
    {
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Frame-Options', 'DENY');
        if (! $response->headers->has('Cache-Control') || $response->headers->get('Cache-Control') === 'no-cache, private') {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}

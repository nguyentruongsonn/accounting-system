<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Add response-level browser protections owned by the application.
 *
 * The SPA shell still needs an edge/server CSP and anti-framing policy; this
 * middleware intentionally does not pretend to replace that deployment
 * control.  The API receives a deny-by-default CSP because it never serves
 * executable browser content, while the other headers are safe for JSON and
 * file responses alike.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Permissions-Policy', 'camera=(), geolocation=(), microphone=(), payment=(), usb=()');

        if ($request->is('api/*')) {
            // JSON/API responses must not be stored by an intermediary or a
            // shared browser cache.  This is especially important for user,
            // accounting and report payloads.
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('Content-Security-Policy', "default-src 'none'; base-uri 'none'; frame-ancestors 'none'");
        }

        return $response;
    }
}

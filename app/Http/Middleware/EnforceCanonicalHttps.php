<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceCanonicalHttps
{
    /**
     * Redirect to the canonical HTTPS host in production and send HSTS headers.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->isProduction()) {
            return $next($request);
        }

        $canonicalHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (! is_string($canonicalHost) || $canonicalHost === '') {
            return $next($request);
        }

        $host = strtolower($request->getHost());
        $needsRedirect = ! $request->secure() || $host !== $canonicalHost;

        if ($needsRedirect) {
            return redirect()->away(
                'https://'.$canonicalHost.$request->getRequestUri(),
                Response::HTTP_MOVED_PERMANENTLY,
            );
        }

        $response = $next($request);

        $response->headers->set(
            'Strict-Transport-Security',
            'max-age=31536000; includeSubDomains',
        );

        return $response;
    }
}

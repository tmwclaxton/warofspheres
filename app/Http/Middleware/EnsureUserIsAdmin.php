<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    /**
     * @var list<string>
     */
    private const ADMIN_EMAILS = [
        'tmwclaxton@gmail.com',
        'toby@grantgunner.org',
    ];

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $email = $request->user()?->email;

        if (! is_string($email) || ! in_array($email, self::ADMIN_EMAILS, true)) {
            abort(Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}

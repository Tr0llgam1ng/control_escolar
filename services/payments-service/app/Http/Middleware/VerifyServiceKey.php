<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Services are only reachable through the API Gateway (or another trusted service).
 * Every request must carry the shared internal key; the gateway also forwards the
 * authenticated user as X-User-Id / X-User-Role, which we expose as the "actor".
 */
class VerifyServiceKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.internal.key');

        if ($expected === '' || ! hash_equals($expected, (string) $request->header('X-Service-Key'))) {
            return response()->json(['message' => 'Invalid or missing service key.'], 401);
        }

        $request->attributes->set('actor', [
            'id' => $request->header('X-User-Id') !== null ? (int) $request->header('X-User-Id') : null,
            'role' => $request->header('X-User-Role'),
        ]);

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware auth server-to-server: nhận Bearer token, lookup User::api_token.
 * Dùng cho endpoint /api/leads/{code}/booking-event nhận push từ lara-sbooking.
 */
class AuthByApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        if (! $token) {
            return response()->json(['error' => 'Missing bearer token'], 401);
        }
        $user = User::where('api_token', $token)->first();
        if (! $user) {
            return response()->json(['error' => 'Invalid token'], 401);
        }
        auth()->setUser($user);
        return $next($request);
    }
}

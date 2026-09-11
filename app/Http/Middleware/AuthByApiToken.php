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
        // 2026-08-03: cho phép shared secret (config services.booking.api_token — cùng chuỗi 2 hệ) —
        //   fallback khi 2 hệ dùng email khác domain, không map được user per-user.
        //   Actor gán user id=1 (admin chính) để log/audit rõ đây là "system push".
        $shared = config('services.booking.api_token');
        if ($shared && hash_equals((string) $shared, (string) $token)) {
            $sysUser = User::find(1) ?? User::first();
            if ($sysUser) auth()->setUser($sysUser);
            return $next($request);
        }
        $user = User::where('api_token', $token)->first();
        if (! $user) {
            return response()->json(['error' => 'Invalid token'], 401);
        }
        auth()->setUser($user);
        return $next($request);
    }
}

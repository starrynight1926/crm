<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    /** $permission có thể là 1 key, hoặc nhiều key phân tách bằng dấu phẩy (chỉ cần có 1). */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $keys = array_filter(array_map('trim', explode(',', $permission)));

        if (! $request->user() || ! $request->user()->hasAnyPermission($keys)) {
            abort(403, 'Bạn không có quyền truy cập chức năng này.');
        }

        return $next($request);
    }
}

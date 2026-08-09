<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function showLogin()
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'login'    => ['required', 'string'],
            'password' => ['required'],
        ], [
            'login.required' => 'Vui lòng nhập tài khoản hoặc email.',
        ]);

        $field = filter_var($data['login'], FILTER_VALIDATE_EMAIL) ? 'email' : 'username';
        $credentials = [$field => $data['login'], 'password' => $data['password']];

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'login' => 'Tài khoản hoặc mật khẩu không đúng.',
            ]);
        }

        /** @var User $user */
        $user = Auth::user();

        if ($user->isLocked()) {
            Auth::logout();
            throw ValidationException::withMessages([
                'login' => 'Tài khoản đã bị khóa. Liên hệ quản trị viên.',
            ]);
        }

        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        // 2026-08-09: bỏ intended() — luôn về landing route mặc định.
        //   Trước đây user session expire ở /leads/13 → login lại → redirect về /leads/13
        //   → nếu lead 13 đã bị xoá/mất quyền → 404/403, user hoảng.
        //   Sau: mọi login đều về Dashboard (hoặc /ups-list cho BO), user thấy trang quen thuộc.
        $request->session()->forget('url.intended');
        return redirect($this->landingRouteFor($user));
    }

    /**
     * Landing page sau login. User không có quyền xem dashboard/lead (VD BO Lễ Tân
     * chỉ có ups.*) thì dội về /ups-list — trang duy nhất họ dùng.
     */
    private function landingRouteFor(User $user): string
    {
        if (! $user->hasAnyPermission(['report.view', 'report.view_all', 'lead.view', 'lead.create', 'lead.import'])
            && $user->hasPermission('ups.view')) {
            return route('ups.list');
        }

        return route('dashboard');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}

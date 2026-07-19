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

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}

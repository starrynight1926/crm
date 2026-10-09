<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Dev tool 2026-08-15 — Admin/DM impersonate user khác để QA nhanh nhiều role.
 * Session giữ 'impersonate_original_id' để có nút "Quay lại".
 * Gate: chỉ user có perm 'user.manage' (Admin/DM) mới start impersonate.
 * Leave route: bất kỳ ai đang impersonate đều leave được.
 */
class ImpersonateController extends Controller
{
    public function start(Request $request, User $user)
    {
        abort_unless(Auth::check(), 403);
        $current = Auth::user();
        abort_unless($current->hasPermission('user.manage'), 403, 'Chỉ Admin/DM mới giả lập được.');
        abort_if($user->id === $current->id, 400, 'Không thể giả lập chính mình.');

        // Nếu đang impersonate rồi → giữ nguyên original_id gốc (không đè bằng persona hiện tại).
        $originalId = $request->session()->get('impersonate_original_id', $current->id);
        $request->session()->put('impersonate_original_id', $originalId);
        $request->session()->put('impersonate_original_name', User::find($originalId)?->name ?? '?');

        Auth::login($user);
        return redirect('/dashboard')->with('status', "Đang giả lập: {$user->name}");
    }

    public function leave(Request $request)
    {
        $origId = $request->session()->pull('impersonate_original_id');
        $request->session()->forget('impersonate_original_name');
        if (! $origId) return redirect('/dashboard');
        $orig = User::find($origId);
        if (! $orig) { Auth::logout(); return redirect()->route('login'); }
        Auth::login($orig);
        return redirect('/dev/quick-login')->with('status', "Đã về {$orig->name}.");
    }

    public function quickLogin()
    {
        abort_unless(app()->environment('local'), 404);
        abort_unless(Auth::check() && Auth::user()->hasPermission('user.manage'), 403);

        $users = User::with(['assignments.role', 'assignments.orgUnit'])
            ->where('status', User::STATUS_ACTIVE)
            ->orderBy('email')
            ->get()
            ->map(function ($u) {
                $asn = $u->assignments->first();
                return [
                    'id' => $u->id, 'name' => $u->name, 'email' => $u->email,
                    'role' => $asn?->role?->name ?? '(không role)',
                    'org' => $asn?->orgUnit?->name ?? '',
                    'branch' => $this->guessBranch($u->email, $asn?->orgUnit?->path ?? ''),
                ];
            })
            ->groupBy('branch');

        return view('dev.quick-login', ['groups' => $users]);
    }

    private function guessBranch(string $email, string $path): string
    {
        if (str_starts_with($email, 'hn.') || str_contains($path, '/2/')) return 'HN';
        if (str_starts_with($email, 'hcm.') || str_contains($path, '/12/')) return 'HCM';
        if (str_starts_with($email, 'dn.') || str_contains($path, '/19/')) return 'ĐN';
        if (str_contains($email, 'admin.hn')) return 'HN';
        if (str_contains($email, 'admin.hcm')) return 'HCM';
        if (str_contains($email, 'admin.dn')) return 'ĐN';
        return 'Khác / Toàn công ty';
    }
}

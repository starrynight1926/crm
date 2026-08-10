<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DailyAttendance;
use App\Models\User;
use App\Services\Ups\UpsDispatcher;
use Illuminate\Http\Request;

/**
 * Phase 6.25.C — Endpoint UPS busy/free từ sbooking.
 *
 * Auth: middleware `scrm.token` (đã có, share với luồng callback booking).
 * Sale ID resolve theo: sale_user_id (nếu sbooking đã map) hoặc email (fallback).
 */
class UpsAttendanceController extends Controller
{
    public function busy(Request $request)
    {
        return $this->toggle($request, true);
    }

    public function complete(Request $request)
    {
        return $this->toggle($request, false);
    }

    /** 2026-08-10 — Sale tự tick "Dừng nhận lead" bên booking → loại khỏi vòng chia. */
    public function pause(Request $request)
    {
        return $this->pauseToggle($request, true);
    }

    public function resume(Request $request)
    {
        return $this->pauseToggle($request, false);
    }

    private function pauseToggle(Request $request, bool $isPaused)
    {
        [$user, $att, $workDate, $err] = $this->resolveAttendance($request);
        if ($err) return $err;

        $dispatcher = app(UpsDispatcher::class);
        $isPaused ? $dispatcher->markPause($user->id, $workDate) : $dispatcher->markResume($user->id, $workDate);

        return response()->json([
            'ok' => true,
            'sale_user_id' => $user->id,
            'sale_name' => $user->name,
            'dung_nhan_lead' => $isPaused,
        ]);
    }

    /** Chuẩn hóa lookup sale + attendance để tái dùng cho busy/pause. */
    private function resolveAttendance(Request $request): array
    {
        $data = $request->validate([
            'sale_user_id' => ['nullable', 'integer'],
            'sale_email'   => ['nullable', 'email'],
            'work_date'    => ['nullable', 'date'],
        ]);

        $user = null;
        if (! empty($data['sale_user_id'])) {
            $user = User::find($data['sale_user_id']);
        }
        if (! $user && ! empty($data['sale_email'])) {
            $user = User::firstWhere('email', $data['sale_email']);
        }
        if (! $user) {
            return [null, null, null, response()->json(['ok' => false, 'reason' => 'Không tìm thấy sale (email: ' . ($data['sale_email'] ?? '?') . ').'], 404)];
        }

        $workDate = $data['work_date'] ?? now()->toDateString();
        $att = DailyAttendance::where('user_id', $user->id)->whereDate('work_date', $workDate)->first();
        if (! $att) {
            return [null, null, null, response()->json(['ok' => false, 'reason' => 'Sale ' . $user->email . ' chưa check-in UPS ngày ' . $workDate . '.'], 404)];
        }

        return [$user, $att, $workDate, null];
    }

    private function toggle(Request $request, bool $isBusy)
    {
        $data = $request->validate([
            'sale_user_id' => ['nullable', 'integer'],
            'sale_email'   => ['nullable', 'email'],
            'work_date'    => ['nullable', 'date'],
        ]);

        $user = null;
        if (! empty($data['sale_user_id'])) {
            $user = User::find($data['sale_user_id']);
        }
        if (! $user && ! empty($data['sale_email'])) {
            $user = User::firstWhere('email', $data['sale_email']);
        }
        if (! $user) {
            return response()->json(['ok' => false, 'reason' => 'Không tìm thấy sale.'], 404);
        }

        $workDate = $data['work_date'] ?? now()->toDateString();
        $att = DailyAttendance::where('user_id', $user->id)->whereDate('work_date', $workDate)->first();
        if (! $att) {
            return response()->json(['ok' => false, 'reason' => 'Sale này chưa check-in UPS hôm nay.'], 404);
        }

        $dispatcher = app(UpsDispatcher::class);
        $isBusy ? $dispatcher->markBusy($user->id, $workDate) : $dispatcher->markFree($user->id, $workDate);

        event(new \App\Events\UpsBusyChanged($user->id, $user->name, $isBusy));

        return response()->json([
            'ok' => true,
            'sale_user_id' => $user->id,
            'sale_name' => $user->name,
            'is_busy' => $isBusy,
        ]);
    }
}

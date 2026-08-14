<?php

namespace App\Http\Controllers;

use App\Models\DailyAttendance;
use App\Services\Ups\UpsDispatcher;

/**
 * B3 (2026-08-14) — Trạng thái nhận lead của chính user.
 *
 * Toggle "Không tiếp nhận" (dung_nhan_lead=true) ↔ "Tiếp tục nhận" (false)
 * cho attendance hôm nay của user. Chỉ áp dụng cho user đã check-in hôm nay.
 */
class MeStatusController extends Controller
{
    public function toggleReceive()
    {
        $user = auth()->user();
        $workDate = now()->toDateString();

        $att = DailyAttendance::where('user_id', $user->id)
            ->whereDate('work_date', $workDate)
            ->first();

        if (! $att) {
            return back()->with('error', 'Bạn chưa check-in UPS hôm nay.');
        }

        $newPaused = ! $att->dung_nhan_lead;
        $dispatcher = app(UpsDispatcher::class);
        $newPaused ? $dispatcher->markPause($user->id, $workDate) : $dispatcher->markResume($user->id, $workDate);

        return back()->with('status', $newPaused ? 'Đã tạm ngừng nhận lead.' : 'Đã tiếp tục nhận lead.');
    }
}

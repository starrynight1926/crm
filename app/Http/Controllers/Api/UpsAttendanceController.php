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
    /**
     * 2026-08-18 — Sbooking gọi khi mở modal "Duyệt" để pick Sale tiếp đón.
     * Trả list sale đang check-in UPS hôm nay ở facility tương ứng sbooking_co_so_id,
     * scope bucket = A/B/C/OFF (Sale tiếp đón, khác MKT là Tele).
     *
     * Query: ?sbooking_co_so_id=X (số hoặc slug tương ứng Facility.sbooking_co_so_id)
     * Response: { data: [{ email, name, list_bucket, is_busy }] }
     */
    public function salesToday(Request $request)
    {
        $sbCoSoId = (int) $request->query('sbooking_co_so_id');
        if (! $sbCoSoId) return response()->json(['data' => []]);

        // Facility datasource ↔ sbooking_co_so_id
        $facility = \App\Models\Facility::where('sbooking_co_so_id', $sbCoSoId)
            ->whereNull('parent_id')->first();
        if (! $facility) return response()->json(['data' => [], 'reason' => 'Facility chưa map sbooking_co_so_id']);

        // Match Facility → OrgUnit branch (depth=1) theo name tokens (VD "Đà Nẵng").
        // Rồi org_pool_map lấy tất cả facility_pool_unit_id thuộc branch đó (1 branch có thể có nhiều cơ sở/địa chỉ).
        $branch = \App\Models\OrgUnit::where('depth', 1)
            ->where('name', 'like', '%' . $facility->name . '%')->first();
        if (! $branch) return response()->json(['data' => [], 'reason' => 'Không tìm được OrgUnit branch theo Facility.name']);

        $orgIds = \App\Models\OrgUnit::where('path', 'like', $branch->path . '%')->pluck('id');
        $poolUnitIds = \Illuminate\Support\Facades\DB::table('org_pool_map')
            ->whereIn('org_unit_id', $orgIds)
            ->join('pool_units', 'pool_units.id', '=', 'org_pool_map.pool_unit_id')
            ->where('pool_units.kind', 'facility')->where('pool_units.is_active', true)
            ->pluck('pool_units.id')->unique()->all();

        if (empty($poolUnitIds)) return response()->json(['data' => [], 'reason' => 'Branch chưa map pool_unit facility']);

        $atts = DailyAttendance::with('user')
            ->whereIn('facility_pool_unit_id', $poolUnitIds)
            ->whereDate('work_date', now()->toDateString())
            ->whereIn('list_bucket', ['A', 'B', 'C', 'OFF'])
            ->where('dung_nhan_lead', false)
            ->orderBy('list_bucket')->orderBy('checkin_at')
            ->get();

        $data = $atts->map(fn ($a) => [
            'email'       => $a->user?->email,
            'name'        => $a->user?->name,
            'list_bucket' => $a->list_bucket,
            'is_busy'     => (bool) $a->is_busy,
        ])->filter(fn ($r) => ! empty($r['email']))->values();

        return response()->json(['data' => $data, 'pool_unit_ids' => $poolUnitIds]);
    }

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

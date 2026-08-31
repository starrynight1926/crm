<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\BookingLog;
use App\Models\CallLog;
use App\Models\Lead;
use App\Models\LeadOwnershipHistory;
use App\Models\LeadStatusLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Inspect endpoints — trả state đầy đủ của 1 entity trong 1 API call.
 * Mục tiêu: gửi id/link, dùng API check ngay không phải db:fresh online.
 */
class InspectController extends BaseV1Controller
{
    public function bookingLog(int $id): JsonResponse
    {
        $bl = BookingLog::with(['lead', 'user', 'facility', 'consultants'])->find($id);
        if (! $bl) return response()->json(['message' => "BookingLog#{$id} không tồn tại"], 404);

        $recentAudit = DB::table('api_audit_logs')
            ->where(function ($q) use ($bl) {
                $q->where('path', 'like', "%booking-logs/{$bl->id}%")
                  ->orWhere('request_body', 'like', "%\"lead_id\":{$bl->lead_id}%");
            })
            ->orderByDesc('id')->limit(15)
            ->get(['id', 'method', 'path', 'response_status', 'created_at']);

        return $this->ok([
            'booking_log' => $bl->toArray(),
            'lead'        => $bl->lead?->only(['id', 'code', 'name', 'phone', 'source_group', 'phase', 'owner_id', 'booking_status']),
            'user'        => $bl->user?->only(['id', 'name', 'email', 'sbooking_user_id']),
            'facility'    => $bl->facility?->only(['id', 'name', 'sbooking_co_so_id']),
            'consultants' => $bl->consultants->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->name, 'email' => $c->email,
                'sbooking_user_id' => $c->sbooking_user_id,
                'position' => $c->pivot->position ?? null,
            ])->values(),
            'sync' => [
                'status'   => $bl->sync_status,
                'error'    => $bl->sync_error,
                'sb_id'    => $bl->sbooking_booking_id,
                'sb_ma'    => $bl->sbooking_booking_ma,
                'synced_at'=> $bl->synced_at,
            ],
            'recent_audit' => $recentAudit,
        ]);
    }

    public function lead(int $id): JsonResponse
    {
        $lead = Lead::with(['owner', 'orgUnit', 'facility'])->find($id);
        if (! $lead) return response()->json(['message' => "Lead#{$id} không tồn tại"], 404);

        $callLogs = CallLog::where('lead_id', $lead->id)
            ->orderByDesc('id')->limit(50)->get();
        $bookingLogs = BookingLog::where('lead_id', $lead->id)
            ->orderByDesc('id')->get(['id', 'type', 'status', 'scheduled_at', 'sync_status', 'sync_error', 'sbooking_booking_id', 'created_at']);
        $ownershipHistory = LeadOwnershipHistory::where('lead_id', $lead->id)
            ->orderByDesc('id')->limit(30)->get();
        $statusLogs = LeadStatusLog::where('lead_id', $lead->id)
            ->orderByDesc('id')->limit(30)->get(['id', 'field', 'old_value', 'new_value', 'changed_by', 'created_at']);

        return $this->ok([
            'lead'          => $lead->toArray(),
            'owner'         => $lead->owner?->only(['id', 'name', 'email', 'sbooking_user_id']),
            'org_unit'      => $lead->orgUnit?->only(['id', 'name', 'code', 'path']),
            'facility'      => $lead->facility?->only(['id', 'name', 'sbooking_co_so_id']),
            'call_logs'     => $callLogs,
            'booking_logs'  => $bookingLogs,
            'ownership_history' => $ownershipHistory,
            'status_logs'   => $statusLogs,
        ]);
    }
}

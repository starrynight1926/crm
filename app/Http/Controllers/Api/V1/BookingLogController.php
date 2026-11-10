<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\BookingLog;
use App\Services\SbookingClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BookingLogController extends BaseV1Controller
{
    private const ALLOWED_FILTERS = ['lead_id', 'user_id', 'facility_id', 'type', 'status',
        'sync_status', 'sbooking_booking_id'];
    private const ALLOWED_SORT    = ['id', 'scheduled_at', 'created_at'];

    public function index(Request $req): JsonResponse
    {
        $q = BookingLog::query();
        $q = $this->applyFilters($q, $req, self::ALLOWED_FILTERS);
        if ($from = $req->input('from')) $q->whereDate('scheduled_at', '>=', $from);
        if ($to   = $req->input('to'))   $q->whereDate('scheduled_at', '<=', $to);
        $q = $this->applySort($q, $req, self::ALLOWED_SORT, '-scheduled_at');
        return $this->paginate($q, $req);
    }

    public function show(BookingLog $booking_log): JsonResponse
    {
        return $this->ok($booking_log);
    }

    public function store(Request $req): JsonResponse
    {
        return $this->ok(BookingLog::create($this->validated($req)), 201);
    }

    public function update(Request $req, BookingLog $booking_log): JsonResponse
    {
        $booking_log->update($this->validated($req, $booking_log->id));
        return $this->ok($booking_log->fresh());
    }

    public function destroy(BookingLog $booking_log): JsonResponse
    {
        $booking_log->delete();
        return response()->json(['data' => ['deleted' => true, 'id' => $booking_log->id]]);
    }

    /** POST /booking-logs/{id}/push — force retry push sang SBooking (khi sync stuck). */
    public function push(BookingLog $booking_log): JsonResponse
    {
        $ok = app(SbookingClient::class)->pushBooking($booking_log->fresh());
        $bl = $booking_log->fresh();
        return response()->json([
            'data' => [
                'ok' => $ok,
                'id' => $bl->id,
                'sync_status' => $bl->sync_status,
                'sync_error' => $bl->sync_error,
                'sbooking_booking_id' => $bl->sbooking_booking_id,
            ],
        ]);
    }

    /**
     * GET /booking-logs/export?from=&to=&group=day|week|month|year
     */
    public function export(Request $req): JsonResponse
    {
        $req->validate([
            'from'  => ['nullable', 'date'],
            'to'    => ['nullable', 'date', 'after_or_equal:from'],
            'group' => ['nullable', Rule::in(['day', 'week', 'month', 'year'])],
        ]);
        $group = $req->input('group', 'day');
        $fmt = match ($group) {
            'week'  => "DATE_FORMAT(scheduled_at, '%x-W%v')",
            'month' => "DATE_FORMAT(scheduled_at, '%Y-%m')",
            'year'  => "DATE_FORMAT(scheduled_at, '%Y')",
            default => "DATE_FORMAT(scheduled_at, '%Y-%m-%d')",
        };
        $q = BookingLog::query()->selectRaw("$fmt as period, count(*) as total");
        $q = $this->applyFilters($q, $req, self::ALLOWED_FILTERS);
        if ($from = $req->input('from')) $q->whereDate('scheduled_at', '>=', $from);
        if ($to   = $req->input('to'))   $q->whereDate('scheduled_at', '<=', $to);
        $rows = $q->groupBy(\DB::raw($fmt))->orderBy('period')->get();
        return response()->json([
            'data' => $rows,
            'meta' => [
                'group' => $group,
                'from'  => $req->input('from'),
                'to'    => $req->input('to'),
                'total' => $rows->sum('total'),
            ],
        ]);
    }

    private function validated(Request $req, ?int $ignoreId = null): array
    {
        return $req->validate([
            'lead_id'         => [$ignoreId ? 'sometimes' : 'required', 'integer', Rule::exists('leads', 'id')],
            'user_id'         => ['nullable', 'integer', Rule::exists('users', 'id')],
            'type'            => ['nullable', Rule::in(['kham_ls', 'tu_van', 'dich_vu', 'tham_kham'])],
            'status'          => ['nullable', 'string', 'max:50'],
            'scheduled_at'    => ['nullable', 'date'],
            'scheduled_end_at'=> ['nullable', 'date'],
            'facility_id'     => ['nullable', 'integer', Rule::exists('facilities', 'id')],
            'sb_phong_id'     => ['nullable', 'integer'],
            'sb_bac_si_id'    => ['nullable', 'integer'],
            'sb_dich_vu_id'   => ['nullable', 'integer'],
            'service_id'      => ['nullable', 'integer'],
            'note'            => ['nullable', 'string'],
            'sync_status'     => ['nullable', 'string', 'max:50'],
        ]);
    }
}

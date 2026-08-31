<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeadController extends BaseV1Controller
{
    private const ALLOWED_FILTERS = ['facility_id', 'owner_id', 'org_unit_id', 'source_group',
        'phase', 'classification', 'status_1', 'status_2', 'booking_status', 'pool_level'];
    private const ALLOWED_SORT    = ['id', 'received_date', 'assigned_at', 'created_at'];
    private const SEARCHABLE      = ['name', 'phone', 'code', 'insight'];

    public function index(Request $req): JsonResponse
    {
        $req->attributes->set('_searchable', self::SEARCHABLE);
        $q = Lead::query();
        $q = $this->applyFilters($q, $req, self::ALLOWED_FILTERS);
        if ($from = $req->input('from')) $q->whereDate('received_date', '>=', $from);
        if ($to   = $req->input('to'))   $q->whereDate('received_date', '<=', $to);
        $q = $this->applySort($q, $req, self::ALLOWED_SORT, '-received_date');
        return $this->paginate($q, $req);
    }

    public function show(Lead $lead): JsonResponse
    {
        return $this->ok($lead);
    }

    public function store(Request $req): JsonResponse
    {
        return $this->ok(Lead::create($this->validated($req)), 201);
    }

    public function update(Request $req, Lead $lead): JsonResponse
    {
        $lead->update($this->validated($req, $lead->id));
        return $this->ok($lead->fresh());
    }

    public function destroy(Lead $lead): JsonResponse
    {
        $lead->delete(); // soft delete
        return response()->json(['data' => ['deleted' => true, 'id' => $lead->id]]);
    }

    /**
     * GET /leads/export?from=&to=&group=day|week|month|year&filter[...]
     * Trả JSON aggregate {period_key: count}.
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
            'week'  => "DATE_FORMAT(received_date, '%x-W%v')",
            'month' => "DATE_FORMAT(received_date, '%Y-%m')",
            'year'  => "DATE_FORMAT(received_date, '%Y')",
            default => "DATE_FORMAT(received_date, '%Y-%m-%d')",
        };
        $q = Lead::query()->selectRaw("$fmt as period, count(*) as total");
        $q = $this->applyFilters($q, $req, self::ALLOWED_FILTERS);
        if ($from = $req->input('from')) $q->whereDate('received_date', '>=', $from);
        if ($to   = $req->input('to'))   $q->whereDate('received_date', '<=', $to);
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
            'name'           => [$ignoreId ? 'sometimes' : 'required', 'string', 'max:255'],
            'phone'          => [$ignoreId ? 'sometimes' : 'required', 'string', 'max:50'],
            'received_date'  => ['nullable', 'date'],
            'source_group'   => ['nullable', 'string', 'max:50'],
            'insight'        => ['nullable', 'string'],
            'link'           => ['nullable', 'string', 'max:500'],
            'region'         => ['nullable', 'string', 'max:100'],
            'classification' => ['nullable', 'string', 'max:100'],
            'status_1'       => ['nullable', 'string', 'max:100'],
            'status_2'       => ['nullable', 'string', 'max:100'],
            'note'           => ['nullable', 'string'],
            'facility_id'    => ['nullable', 'integer', Rule::exists('facilities', 'id')],
            'owner_id'       => ['nullable', 'integer', Rule::exists('users', 'id')],
            'org_unit_id'    => ['nullable', 'integer', Rule::exists('org_units', 'id')],
            'imported_by'    => ['nullable', 'integer', Rule::exists('users', 'id')],
            'booking_status' => ['nullable', 'string', 'max:50'],
            'phase'          => ['nullable', 'integer', 'min:1', 'max:9'],
        ]);
    }
}

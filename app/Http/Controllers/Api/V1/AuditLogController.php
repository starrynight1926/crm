<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuditLogController extends BaseV1Controller
{
    private const ALLOWED_FILTERS = ['method', 'response_status', 'actor_id'];
    private const ALLOWED_SORT    = ['id', 'created_at', 'response_status'];

    public function index(Request $req): JsonResponse
    {
        $q = DB::table('api_audit_logs');
        foreach (['method', 'response_status', 'actor_id'] as $f) {
            if (($v = $req->input("filter.$f")) !== null && $v !== '') $q->where($f, $v);
        }
        if ($path = $req->input('filter.path')) $q->where('path', 'like', '%' . $path . '%');
        if ($from = $req->input('from')) $q->where('created_at', '>=', $from);
        if ($to   = $req->input('to'))   $q->where('created_at', '<=', $to);
        $q->orderByDesc('id');
        $per = min(200, max(1, (int) $req->input('per_page', 25)));
        $p = $q->paginate($per);
        return response()->json([
            'data' => $p->items(),
            'meta' => [
                'total' => $p->total(), 'per_page' => $p->perPage(),
                'current_page' => $p->currentPage(), 'last_page' => $p->lastPage(),
            ],
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class BaseV1Controller extends Controller
{
    protected function applyFilters(Builder $q, Request $req, array $allowed): Builder
    {
        foreach ((array) $req->input('filter', []) as $key => $val) {
            if (! in_array($key, $allowed, true)) continue;
            if ($val === '' || $val === null) continue;
            is_array($val) ? $q->whereIn($key, $val) : $q->where($key, $val);
        }
        if ($search = trim((string) $req->input('q', ''))) {
            $searchable = $req->attributes->get('_searchable', []);
            $q->where(function ($qq) use ($searchable, $search) {
                foreach ($searchable as $col) {
                    $qq->orWhere($col, 'like', '%' . $search . '%');
                }
            });
        }
        return $q;
    }

    protected function applySort(Builder $q, Request $req, array $allowed, string $default = '-id'): Builder
    {
        $sort = (string) $req->input('sort', $default);
        foreach (explode(',', $sort) as $s) {
            $s = trim($s);
            if ($s === '') continue;
            $dir = 'asc';
            if (str_starts_with($s, '-')) { $dir = 'desc'; $s = ltrim($s, '-'); }
            if (in_array($s, $allowed, true)) $q->orderBy($s, $dir);
        }
        return $q;
    }

    protected function paginate(Builder $q, Request $req): JsonResponse
    {
        $per = min(200, max(1, (int) $req->input('per_page', 25)));
        $p = $q->paginate($per);
        return response()->json([
            'data' => $p->items(),
            'meta' => [
                'total'        => $p->total(),
                'per_page'     => $p->perPage(),
                'current_page' => $p->currentPage(),
                'last_page'    => $p->lastPage(),
            ],
        ]);
    }

    protected function ok($data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data], $status);
    }
}

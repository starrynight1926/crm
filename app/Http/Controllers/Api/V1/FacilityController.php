<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Facility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FacilityController extends BaseV1Controller
{
    private const ALLOWED_FILTERS = ['parent_id', 'active', 'booking_co_so_slug', 'sbooking_co_so_id'];
    private const ALLOWED_SORT    = ['id', 'name', 'created_at'];
    private const SEARCHABLE      = ['name'];

    public function index(Request $req): JsonResponse
    {
        $req->attributes->set('_searchable', self::SEARCHABLE);
        $q = Facility::query();
        if ($req->boolean('roots_only')) $q->whereNull('parent_id');
        $q = $this->applyFilters($q, $req, self::ALLOWED_FILTERS);
        $q = $this->applySort($q, $req, self::ALLOWED_SORT);
        return $this->paginate($q, $req);
    }

    public function show(Facility $facility): JsonResponse
    {
        return $this->ok($facility);
    }

    public function store(Request $req): JsonResponse
    {
        return $this->ok(Facility::create($this->validated($req)), 201);
    }

    public function update(Request $req, Facility $facility): JsonResponse
    {
        $facility->update($this->validated($req, $facility->id));
        return $this->ok($facility->fresh());
    }

    public function destroy(Facility $facility): JsonResponse
    {
        if ($facility->children()->exists()) {
            return response()->json(['message' => 'Còn facility con — không thể xoá.'], 422);
        }
        $facility->delete();
        return response()->json(['data' => ['deleted' => true, 'id' => $facility->id]]);
    }

    private function validated(Request $req, ?int $ignoreId = null): array
    {
        return $req->validate([
            'name'               => [$ignoreId ? 'sometimes' : 'required', 'string', 'max:255'],
            'parent_id'          => ['nullable', 'integer', Rule::exists('facilities', 'id')],
            'active'             => ['sometimes', 'boolean'],
            'booking_co_so_slug' => ['nullable', 'string', 'max:100'],
            'sbooking_co_so_id'  => ['nullable', 'integer'],
        ]);
    }
}

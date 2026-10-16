<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\OrgUnit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrgUnitController extends BaseV1Controller
{
    private const ALLOWED_FILTERS = ['parent_id', 'active', 'code', 'depth'];
    private const ALLOWED_SORT    = ['id', 'name', 'position', 'depth', 'created_at'];
    private const SEARCHABLE      = ['name', 'code'];

    public function index(Request $req): JsonResponse
    {
        $req->attributes->set('_searchable', self::SEARCHABLE);
        $q = OrgUnit::query();
        if ($req->boolean('roots_only')) $q->whereNull('parent_id');
        $q = $this->applyFilters($q, $req, self::ALLOWED_FILTERS);
        $q = $this->applySort($q, $req, self::ALLOWED_SORT);
        return $this->paginate($q, $req);
    }

    /** GET /org-units/tree — trả cả cây từ roots (không paginate). */
    public function tree(Request $req): JsonResponse
    {
        $all = OrgUnit::orderBy('depth')->orderBy('position')->get();
        $byParent = $all->groupBy('parent_id');
        $build = function ($parentId) use (&$build, $byParent) {
            return ($byParent[$parentId] ?? collect())->map(fn ($n) => [
                'id' => $n->id, 'name' => $n->name, 'code' => $n->code,
                'depth' => $n->depth, 'position' => $n->position, 'active' => $n->active,
                'children' => $build($n->id),
            ])->values();
        };
        return $this->ok($build(null));
    }

    public function show(OrgUnit $org_unit): JsonResponse
    {
        return $this->ok($org_unit);
    }

    public function store(Request $req): JsonResponse
    {
        $data = $this->validated($req);
        $data = $this->deriveDepthAndPath($data);
        return $this->ok(OrgUnit::create($data), 201);
    }

    public function update(Request $req, OrgUnit $org_unit): JsonResponse
    {
        $data = $this->validated($req, $org_unit->id);
        if (array_key_exists('parent_id', $data)) {
            $data = $this->deriveDepthAndPath($data);
        }
        $org_unit->update($data);
        return $this->ok($org_unit->fresh());
    }

    public function destroy(OrgUnit $org_unit): JsonResponse
    {
        if ($org_unit->children()->exists()) {
            return response()->json(['message' => 'Còn org unit con — không thể xoá.'], 422);
        }
        $org_unit->delete();
        return response()->json(['data' => ['deleted' => true, 'id' => $org_unit->id]]);
    }

    /** POST /org-units/{id}/move  body: {parent_id: N|null, position?: N} */
    public function move(Request $req, OrgUnit $org_unit): JsonResponse
    {
        $data = $req->validate([
            'parent_id' => ['present', 'nullable', 'integer', Rule::exists('org_units', 'id')],
            'position'  => ['sometimes', 'integer', 'min:0'],
        ]);
        if ($data['parent_id'] === $org_unit->id) {
            return response()->json(['message' => 'Không thể move vào chính nó.'], 422);
        }
        $data = $this->deriveDepthAndPath($data);
        $org_unit->update($data);
        return $this->ok($org_unit->fresh());
    }

    private function deriveDepthAndPath(array $data): array
    {
        $parentId = $data['parent_id'] ?? null;
        if ($parentId) {
            $parent = OrgUnit::find($parentId);
            $data['depth'] = ($parent->depth ?? 0) + 1;
            $data['path']  = trim((string) $parent->path, '/') . '/' . ($data['id'] ?? '');
        } else {
            $data['depth'] = 0;
            $data['path']  = '/';
        }
        return $data;
    }

    private function validated(Request $req, ?int $ignoreId = null): array
    {
        return $req->validate([
            'name'      => [$ignoreId ? 'sometimes' : 'required', 'string', 'max:255'],
            'code'      => [
                'nullable', 'string', 'max:100',
                Rule::unique('org_units', 'code')->ignore($ignoreId),
            ],
            'parent_id' => ['nullable', 'integer', Rule::exists('org_units', 'id')],
            'position'  => ['sometimes', 'integer', 'min:0'],
            'active'    => ['sometimes', 'boolean'],
        ]);
    }
}

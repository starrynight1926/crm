<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Assignment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * User assignments — nhân sự được gán vào org unit + role.
 * Endpoint dùng nested route: /users/{user}/assignments.
 */
class AssignmentController extends BaseV1Controller
{
    public function index(Request $req, User $user): JsonResponse
    {
        $q = Assignment::where('user_id', $user->id);
        if ($req->has('active')) $q->where('active', $req->boolean('active'));
        return $this->ok($q->with(['role', 'orgUnit'])->orderByDesc('id')->get());
    }

    public function store(Request $req, User $user): JsonResponse
    {
        $data = $req->validate([
            'role_id'     => ['required', 'integer', Rule::exists('roles', 'id')],
            'org_unit_id' => ['required', 'integer', Rule::exists('org_units', 'id')],
            'data_scope'  => ['required', Rule::in([Assignment::SCOPE_SELF, Assignment::SCOPE_TEAM, Assignment::SCOPE_CUSTOM])],
            'active'      => ['sometimes', 'boolean'],
            'valid_from'  => ['nullable', 'date'],
            'valid_to'    => ['nullable', 'date', 'after_or_equal:valid_from'],
        ]);
        $data['user_id'] = $user->id;
        $data['active'] = $data['active'] ?? true;
        return $this->ok(Assignment::create($data), 201);
    }

    public function update(Request $req, User $user, Assignment $assignment): JsonResponse
    {
        if ($assignment->user_id !== $user->id) {
            return response()->json(['message' => 'Assignment không thuộc user này.'], 404);
        }
        $data = $req->validate([
            'role_id'     => ['sometimes', 'integer', Rule::exists('roles', 'id')],
            'org_unit_id' => ['sometimes', 'integer', Rule::exists('org_units', 'id')],
            'data_scope'  => ['sometimes', Rule::in([Assignment::SCOPE_SELF, Assignment::SCOPE_TEAM, Assignment::SCOPE_CUSTOM])],
            'active'      => ['sometimes', 'boolean'],
            'valid_from'  => ['nullable', 'date'],
            'valid_to'    => ['nullable', 'date', 'after_or_equal:valid_from'],
        ]);
        $assignment->update($data);
        return $this->ok($assignment->fresh());
    }

    public function destroy(User $user, Assignment $assignment): JsonResponse
    {
        if ($assignment->user_id !== $user->id) {
            return response()->json(['message' => 'Assignment không thuộc user này.'], 404);
        }
        $assignment->delete();
        return response()->json(['data' => ['deleted' => true, 'id' => $assignment->id]]);
    }
}

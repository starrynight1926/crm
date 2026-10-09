<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends BaseV1Controller
{
    private const ALLOWED_FILTERS = ['status', 'username', 'email', 'sbooking_user_id'];
    private const ALLOWED_SORT    = ['id', 'name', 'username', 'created_at'];
    private const SEARCHABLE      = ['name', 'username', 'email', 'phone', 'job_title'];

    public function index(Request $req): JsonResponse
    {
        $req->attributes->set('_searchable', self::SEARCHABLE);
        $q = User::query();
        $q = $this->applyFilters($q, $req, self::ALLOWED_FILTERS);
        $q = $this->applySort($q, $req, self::ALLOWED_SORT);
        return $this->paginate($q, $req);
    }

    public function show(User $user): JsonResponse
    {
        return $this->ok($user);
    }

    public function store(Request $req): JsonResponse
    {
        $data = $this->validated($req);
        $data['password'] = Hash::make($data['password']);
        $data['status'] = $data['status'] ?? User::STATUS_ACTIVE;
        return $this->ok(User::create($data), 201);
    }

    public function update(Request $req, User $user): JsonResponse
    {
        $data = $this->validated($req, $user->id);
        if (! empty($data['password'])) $data['password'] = Hash::make($data['password']);
        else unset($data['password']);
        $user->update($data);
        return $this->ok($user->fresh());
    }

    public function destroy(User $user): JsonResponse
    {
        $user->update(['status' => User::STATUS_LOCKED]);
        return response()->json(['data' => ['locked' => true, 'id' => $user->id]]);
    }

    private function validated(Request $req, ?int $ignoreId = null): array
    {
        return $req->validate([
            'username'         => [
                $ignoreId ? 'sometimes' : 'required', 'string', 'max:100',
                Rule::unique('users', 'username')->ignore($ignoreId),
            ],
            'name'             => [$ignoreId ? 'sometimes' : 'required', 'string', 'max:255'],
            'email'            => [
                'nullable', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($ignoreId),
            ],
            'phone'            => ['nullable', 'string', 'max:50'],
            'job_title'        => ['nullable', 'string', 'max:100'],
            'status'           => ['sometimes', Rule::in([User::STATUS_ACTIVE, User::STATUS_LOCKED])],
            'password'         => [$ignoreId ? 'nullable' : 'required', 'string', 'min:6'],
            'sbooking_user_id' => ['nullable', 'integer'],
        ]);
    }
}

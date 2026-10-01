<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\OrgUnit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class EnsurePermissionMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::create(['key' => 'foo.a', 'label' => 'A', 'group' => 'foo']);
        Permission::create(['key' => 'foo.b', 'label' => 'B', 'group' => 'foo']);
        Permission::create(['key' => 'foo.c', 'label' => 'C', 'group' => 'foo']);

        Route::middleware(['web', 'auth', 'permission:foo.a'])
            ->get('/_test/single', fn () => 'ok');
        Route::middleware(['web', 'auth', 'permission:foo.a,foo.b'])
            ->get('/_test/multi', fn () => 'ok');
    }

    private function userWith(array $keys): User
    {
        $role = Role::create(['name' => 'R'.uniqid()]);
        $role->permissions()->sync(Permission::whereIn('key', $keys)->pluck('id'));
        $org = OrgUnit::createNode(['name' => 'Org '.uniqid(), 'code' => 'org-'.uniqid()]);
        $user = User::factory()->create();
        Assignment::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'org_unit_id' => $org->id,
            'scope' => Assignment::SCOPE_SELF,
        ]);
        return $user;
    }

    public function test_single_key_pass(): void
    {
        $this->actingAs($this->userWith(['foo.a']))
            ->get('/_test/single')->assertOk();
    }

    public function test_single_key_deny(): void
    {
        $this->actingAs($this->userWith(['foo.c']))
            ->get('/_test/single')->assertForbidden();
    }

    public function test_multi_key_pass_with_first(): void
    {
        $this->actingAs($this->userWith(['foo.a']))
            ->get('/_test/multi')->assertOk();
    }

    public function test_multi_key_pass_with_second(): void
    {
        // Bug cũ: chỉ check key đầu → user chỉ có foo.b bị 403 dù route khai báo cho phép.
        $this->actingAs($this->userWith(['foo.b']))
            ->get('/_test/multi')->assertOk();
    }

    public function test_multi_key_deny_when_none(): void
    {
        $this->actingAs($this->userWith(['foo.c']))
            ->get('/_test/multi')->assertForbidden();
    }

    public function test_guest_denied(): void
    {
        $this->get('/_test/single')->assertStatus(302); // auth middleware redirects
    }
}

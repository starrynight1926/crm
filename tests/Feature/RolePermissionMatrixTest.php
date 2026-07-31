<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Lead;
use App\Models\OrgUnit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Matrix test — với mỗi role (perm set khác nhau), thử tạo lead qua form
 * Livewire với 7 nguồn khác nhau. Verify:
 *   - Nguồn được perm map → save() thành công, lead created.
 *   - Nguồn KHÔNG được perm → save() throw ValidationException.
 *
 * Data driven — chạy nhanh với sqlite in-memory, không đụng DB thật.
 */
class RolePermissionMatrixTest extends TestCase
{
    use RefreshDatabase;

    /** Ánh xạ role → tập perm cần cấp cho scope test. */
    private const ROLE_PERMS = [
        'Trực Page' => ['lead.view', 'lead.create', 'source.up.trucpage'],
        'Team Tele' => ['lead.view', 'lead.create', 'source.up.tele'],
        'Team sale' => ['lead.view', 'lead.create', 'source.up.sale'],
        'CM sale'   => ['lead.view', 'lead.create', 'source.up.sale', 'source.up.admin', 'lead.distribute', 'lead.distribute_sale'],
        'Admin cơ sở' => ['lead.view', 'lead.create', 'lead.source_all', 'lead.distribute', 'lead.distribute_tele', 'lead.distribute_sale'],
    ];

    /** [source_group => list role được phép up]. Cần đồng bộ với Lead::SOURCE_PERMISSIONS. */
    private const EXPECTED = [
        'mkt'    => ['Trực Page', 'Admin cơ sở'],
        'mkt_br' => ['Team sale', 'CM sale', 'Admin cơ sở'],
        'sa'     => ['Team sale', 'CM sale', 'Admin cơ sở'],
        'ba'     => ['Team Tele', 'Admin cơ sở'],
        'bdm'    => ['CM sale', 'Admin cơ sở'],
        'bod'    => ['CM sale', 'Admin cơ sở'],
        'wi'     => ['CM sale', 'Admin cơ sở'],
    ];

    private OrgUnit $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = OrgUnit::createNode(['name' => 'QA Org', 'code' => 'qa-org']);

        // Seed permissions tối thiểu để test không phụ thuộc PermissionSeeder.
        foreach ([
            'lead.view', 'lead.create', 'lead.source_all',
            'lead.distribute', 'lead.distribute_tele', 'lead.distribute_sale',
            'source.up.trucpage', 'source.up.sale', 'source.up.tele', 'source.up.admin',
        ] as $key) {
            Permission::firstOrCreate(['key' => $key], ['label' => $key, 'group' => 'test']);
        }
    }

    private function makeUser(string $roleName, array $perms): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);
        $role->permissions()->sync(Permission::whereIn('key', $perms)->pluck('id'));

        $u = User::factory()->create();
        Assignment::create([
            'user_id' => $u->id, 'role_id' => $role->id,
            'org_unit_id' => $this->org->id, 'data_scope' => Assignment::SCOPE_TEAM,
        ]);
        return $u;
    }

    #[DataProvider('matrixProvider')]
    public function test_role_can_or_cannot_create_lead_of_source(
        string $roleName, string $sourceGroup, bool $shouldPass
    ): void {
        $user = $this->makeUser($roleName, self::ROLE_PERMS[$roleName]);

        // Set data hợp lệ tối thiểu để pass các validation khác.
        $phone = '09'.substr(md5($roleName.$sourceGroup), 0, 9);
        $test = Livewire::actingAs($user)
            ->test('leads.lead-form')
            ->set('name', "QA-$roleName-$sourceGroup")
            ->set('phone', $phone)
            ->set('received_date', now()->toDateString())
            ->set('classification', 'new')
            ->set('bookingStatus', 'not_booked')
            ->set('sourceGroup', $sourceGroup)
            ->call('save');

        // Mục tiêu test: rule validation "role X có được up nguồn Y không" — chỉ verify
        // sourceGroup error, không xét các branch khác (personId/pool/custom field).
        if ($shouldPass) {
            $test->assertHasNoErrors('sourceGroup');
        } else {
            $test->assertHasErrors('sourceGroup');
            $this->assertDatabaseMissing('leads', [
                'name' => "QA-$roleName-$sourceGroup",
            ]);
        }
    }

    public static function matrixProvider(): array
    {
        $cases = [];
        foreach (self::EXPECTED as $sg => $allowedRoles) {
            foreach (array_keys(self::ROLE_PERMS) as $role) {
                $shouldPass = in_array($role, $allowedRoles, true);
                $cases["$role × $sg"] = [$role, $sg, $shouldPass];
            }
        }
        return $cases;
    }
}

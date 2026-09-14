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
use Tests\TestCase;

class LeadListActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private OrgUnit $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = OrgUnit::createNode(['name' => 'Team X', 'code' => 'team-x']);

        $role = Role::create(['name' => 'SaleX']);
        foreach (['lead.view', 'lead.update', 'lead.delete'] as $key) {
            $role->permissions()->attach(Permission::create(['key' => $key, 'label' => $key, 'group' => 'lead'])->id);
        }

        $this->user = User::factory()->create();
        Assignment::create([
            'user_id' => $this->user->id,
            'role_id' => $role->id,
            'org_unit_id' => $this->org->id,
            'data_scope' => 'team',
        ]);
    }

    private function makeLead(array $attrs = []): Lead
    {
        static $n = 0;
        $n++;

        return Lead::create(array_merge([
            'received_date' => now()->toDateString(),
            'name' => 'KH ' . $n,
            'phone' => '+849000000' . str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'org_unit_id' => $this->org->id,
            'owner_id' => $this->user->id,
            'classification' => 'new',
            'pool_level' => Lead::POOL_PERSONAL,
        ], $attrs));
    }

    public function test_list_renders_stt_and_action_columns(): void
    {
        $this->makeLead();

        Livewire::actingAs($this->user)->test('leads.lead-list')
            ->assertSee('STT')
            ->assertSee('Thao tác');
    }

    public function test_delete_lead_soft_deletes_visible_lead(): void
    {
        $lead = $this->makeLead();

        Livewire::actingAs($this->user)->test('leads.lead-list')
            ->call('deleteLead', $lead->id)
            ->assertSee('Đã xóa');

        $this->assertSoftDeleted('leads', ['id' => $lead->id]);
    }

    public function test_delete_lead_outside_scope_is_ignored(): void
    {
        $otherOrg = OrgUnit::createNode(['name' => 'Team Y', 'code' => 'team-y']);
        $foreign = $this->makeLead(['org_unit_id' => $otherOrg->id, 'owner_id' => null]);

        Livewire::actingAs($this->user)->test('leads.lead-list')
            ->call('deleteLead', $foreign->id);

        $this->assertNotSoftDeleted('leads', ['id' => $foreign->id]);
    }

    public function test_delete_selected_bulk(): void
    {
        $a = $this->makeLead();
        $b = $this->makeLead();

        Livewire::actingAs($this->user)->test('leads.lead-list')
            ->set('selected', [(string) $a->id, (string) $b->id])
            ->call('deleteSelected')
            ->assertSet('selected', []);

        $this->assertSoftDeleted('leads', ['id' => $a->id]);
        $this->assertSoftDeleted('leads', ['id' => $b->id]);
    }

    public function test_select_all_fills_current_page(): void
    {
        $this->makeLead();
        $this->makeLead();
        $this->makeLead();

        Livewire::actingAs($this->user)->test('leads.lead-list')
            ->set('selectAll', true)
            ->assertCount('selected', 3);
    }

    public function test_delete_requires_permission(): void
    {
        $role = Role::create(['name' => 'Viewer']);
        $role->permissions()->attach(Permission::firstWhere('key', 'lead.view')->id);
        $viewer = User::factory()->create();
        Assignment::create(['user_id' => $viewer->id, 'role_id' => $role->id, 'org_unit_id' => $this->org->id, 'data_scope' => 'team']);

        $lead = $this->makeLead();

        Livewire::actingAs($viewer)->test('leads.lead-list')
            ->call('deleteLead', $lead->id)
            ->assertForbidden();

        $this->assertNotSoftDeleted('leads', ['id' => $lead->id]);
    }
}

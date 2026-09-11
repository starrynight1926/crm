<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\CustomField;
use App\Models\ImportTemplate;
use App\Models\Lead;
use App\Models\LeadCustomValue;
use App\Models\OrgUnit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class ImportComponentTest extends TestCase
{
    use RefreshDatabase;

    private User $importer;
    private CustomField $cf;

    protected function setUp(): void
    {
        parent::setUp();

        $root = OrgUnit::createNode(['name' => 'Công ty', 'code' => 'company']);
        $perm = Permission::create(['key' => 'lead.import', 'label' => 'Import', 'group' => 'lead']);
        $role = Role::create(['name' => 'Importer']);
        $role->permissions()->attach($perm->id);

        $this->importer = User::factory()->create();
        Assignment::create([
            'user_id' => $this->importer->id,
            'role_id' => $role->id,
            'org_unit_id' => $root->id,
            'data_scope' => 'team',
        ]);

        // Trường tùy biến mức công ty để map từ file
        $this->cf = CustomField::create([
            'org_unit_id' => null, 'key' => 'nhu_cau', 'label' => 'Nhu cầu',
            'field_type' => 'text', 'required' => false, 'status' => 'active', 'active' => true,
        ]);
    }

    private function csvFile(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('import.csv', $content);
    }

    public function test_import_with_defaults_and_custom_field(): void
    {
        $csv = "Ho ten,SDT,Nhu cau\n"
             . "Nguyen Test,0987000111,Goi A\n"
             . ",,\n"; // dòng rỗng Tên+SĐT → bỏ

        $cfKey = 'cf_' . $this->cf->id;

        Livewire::actingAs($this->importer)
            ->test('leads.lead-import')
            ->set('file', $this->csvFile($csv))
            ->set('mapping.name', '0')
            ->set('mapping.phone', '1')
            ->set("mapping.$cfKey", '2')
            ->set('defaults.camp', 'Tự do') // camp không map → điền mặc định
            ->call('import');

        // queue sync → ProcessRawLead chạy inline
        $this->assertSame(1, Lead::count()); // dòng rỗng bị bỏ
        $lead = Lead::first();
        $this->assertSame('Nguyen Test', $lead->name);
        $this->assertSame('0987000111', $lead->phone);
        $this->assertSame('Tự do', $lead->camp); // default áp
        $this->assertSame('Goi A', LeadCustomValue::where('lead_id', $lead->id)->where('custom_field_id', $this->cf->id)->value('value'));
    }

    public function test_save_and_apply_template(): void
    {
        $csv = "Ho ten,SDT,Camp\nA,0900000001,C1\n";

        // Lưu template từ map hiện tại
        Livewire::actingAs($this->importer)
            ->test('leads.lead-import')
            ->set('file', $this->csvFile($csv))
            ->set('mapping.name', '0')
            ->set('mapping.phone', '1')
            ->set('mapping.camp', '2')
            ->set('defaults.region', 'HN')
            ->set('templateName', 'Mẫu test')
            ->call('saveTemplate');

        $tpl = ImportTemplate::where('name', 'Mẫu test')->first();
        $this->assertNotNull($tpl);
        $this->assertSame('HN', collect($tpl->config)->firstWhere('target', 'region')['default']);

        // Áp template lên file mới (cột đảo vị trí nhưng cùng tên header)
        $csv2 = "SDT,Ho ten,Camp\n0900000002,B,C2\n";
        Livewire::actingAs($this->importer)
            ->test('leads.lead-import')
            ->set('file', $this->csvFile($csv2))
            ->set('selectedTemplateId', (string) $tpl->id)
            ->call('applyTemplate')
            ->assertSet('mapping.name', '1')   // Ho ten giờ ở cột index 1
            ->assertSet('mapping.phone', '0')  // SDT ở cột 0
            ->assertSet('defaults.region', 'HN');
    }
}

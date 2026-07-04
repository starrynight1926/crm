<?php

namespace Tests\Feature;

use App\Models\CustomField;
use App\Models\Lead;
use App\Models\OrgUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomFieldTest extends TestCase
{
    use RefreshDatabase;

    private OrgUnit $root;
    private OrgUnit $sales;
    private OrgUnit $teamA;
    private OrgUnit $marketing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = OrgUnit::createNode(['name' => 'Công ty', 'code' => 'company']);
        $this->sales = OrgUnit::createNode(['name' => 'Kinh doanh', 'code' => 'sales'], $this->root);
        $this->teamA = OrgUnit::createNode(['name' => 'Team A', 'code' => 'team-a'], $this->sales);
        $this->marketing = OrgUnit::createNode(['name' => 'Marketing', 'code' => 'mkt'], $this->root);
    }

    private function field(?OrgUnit $org, string $key, array $extra = []): CustomField
    {
        return CustomField::create(array_merge([
            'org_unit_id' => $org?->id,
            'key' => $key,
            'label' => ucfirst($key),
            'field_type' => 'text',
        ], $extra));
    }

    // ---------- Mã khách hàng ----------

    public function test_code_format_with_source(): void
    {
        $lead = Lead::create([
            'received_date' => now()->toDateString(),
            'name' => 'A', 'phone' => '0900000001',
            'type_code' => 'MKT', 'source_code' => 'FB',
        ]);

        $this->assertSame(sprintf('KH-%05d-MKT-FB', $lead->id), $lead->generateCode());
    }

    public function test_code_format_without_source(): void
    {
        $lead = Lead::create([
            'received_date' => now()->toDateString(),
            'name' => 'B', 'phone' => '0900000002',
            'type_code' => 'C',
        ]);

        $this->assertSame(sprintf('KH-%05d-C', $lead->id), $lead->generateCode());
    }

    public function test_code_regenerates_when_type_changes(): void
    {
        $lead = Lead::create([
            'received_date' => now()->toDateString(),
            'name' => 'C', 'phone' => '0900000003',
            'type_code' => 'N',
        ]);
        $lead->generateCode();

        $lead->update(['type_code' => 'BDM']);
        $this->assertSame(sprintf('KH-%05d-BDM', $lead->id), $lead->generateCode());
    }

    public function test_source_code_mapping(): void
    {
        $this->assertSame('FB', Lead::sourceCodeFor('Facebook Ads'));
        $this->assertSame('GG', Lead::sourceCodeFor('Google Ads'));
        $this->assertNull(Lead::sourceCodeFor('Nguồn lạ'));
        $this->assertNull(Lead::sourceCodeFor(null));
    }

    // ---------- Trường tùy biến: phạm vi áp dụng ----------

    public function test_company_level_fields_apply_everywhere(): void
    {
        $companyField = $this->field(null, 'ma_gioi_thieu');

        $this->assertTrue(CustomField::applicableTo($this->teamA)->contains('id', $companyField->id));
        $this->assertTrue(CustomField::applicableTo($this->marketing)->contains('id', $companyField->id));
        $this->assertTrue(CustomField::applicableTo(null)->contains('id', $companyField->id));
    }

    public function test_org_fields_apply_to_own_org_and_descendants(): void
    {
        $salesField = $this->field($this->sales, 'nhu_cau');

        // Team A là con của Kinh doanh → thừa hưởng field của cha
        $this->assertTrue(CustomField::applicableTo($this->teamA)->contains('id', $salesField->id));
        $this->assertTrue(CustomField::applicableTo($this->sales)->contains('id', $salesField->id));
    }

    public function test_org_fields_do_not_leak_to_sibling(): void
    {
        $salesField = $this->field($this->sales, 'nhu_cau');
        $mktField = $this->field($this->marketing, 'kenh_tiep_can');

        $this->assertFalse(CustomField::applicableTo($this->marketing)->contains('id', $salesField->id));
        $this->assertFalse(CustomField::applicableTo($this->teamA)->contains('id', $mktField->id));
    }

    public function test_inactive_fields_excluded(): void
    {
        $field = $this->field(null, 'tam_ngung', ['active' => false]);

        $this->assertFalse(CustomField::applicableTo($this->teamA)->contains('id', $field->id));
    }

    public function test_lead_without_org_gets_company_fields_only(): void
    {
        $companyField = $this->field(null, 'ma_gioi_thieu');
        $this->field($this->sales, 'nhu_cau');

        $applicable = CustomField::applicableTo(null);
        $this->assertCount(1, $applicable);
        $this->assertSame($companyField->id, $applicable->first()->id);
    }
}

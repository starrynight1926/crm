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

    /** Field kiểu "mã phân loại" cố định (nối mã). */
    private function codeFieldFixed(?OrgUnit $org, string $key, string $value, int $pos, string $status = 'active'): CustomField
    {
        return $this->field($org, $key, [
            'field_type' => 'code', 'affects_code' => true,
            'rules' => ['code_kind' => 'fixed', 'fixed_value' => $value],
            'position' => $pos, 'status' => $status,
        ]);
    }

    public function test_code_bare_without_classification(): void
    {
        $lead = Lead::create([
            'received_date' => now()->toDateString(),
            'name' => 'A', 'phone' => '0900000001',
        ]);

        $this->assertSame('KH-' . str_pad((string) $lead->id, 3, '0', STR_PAD_LEFT), $lead->generateCode());
    }

    public function test_code_appends_classification_top_down(): void
    {
        // Công ty: năm 2026; Team A: định danh SL (pos1) + camp nhập tay (pos2)
        $this->codeFieldFixed(null, 'nam', '2026', 1);
        $this->codeFieldFixed($this->teamA, 'dinhdanh', 'SL', 1);
        $camp = $this->field($this->teamA, 'camp', [
            'field_type' => 'code', 'affects_code' => true,
            'rules' => ['code_kind' => 'input'], 'position' => 2,
        ]);

        $lead = Lead::create([
            'received_date' => now()->toDateString(), 'name' => 'B', 'phone' => '0900000002',
            'org_unit_id' => $this->teamA->id,
        ]);
        $lead->customValues()->create(['custom_field_id' => $camp->id, 'value' => 'FB']);
        $lead->load('customValues');

        $this->assertSame('KH-' . str_pad((string) $lead->id, 3, '0', STR_PAD_LEFT) . '-2026-SL-FB', $lead->generateCode());
    }

    public function test_code_regenerates_when_value_changes(): void
    {
        $camp = $this->field($this->teamA, 'camp', [
            'field_type' => 'code', 'affects_code' => true,
            'rules' => ['code_kind' => 'input'], 'position' => 1,
        ]);
        $lead = Lead::create([
            'received_date' => now()->toDateString(), 'name' => 'C', 'phone' => '0900000003',
            'org_unit_id' => $this->teamA->id,
        ]);
        $val = $lead->customValues()->create(['custom_field_id' => $camp->id, 'value' => 'FB']);
        $lead->load('customValues');
        $this->assertStringEndsWith('-FB', $lead->generateCode());

        $val->update(['value' => 'GG']);
        $lead->load('customValues');
        $this->assertStringEndsWith('-GG', $lead->generateCode());
    }

    public function test_pending_classification_field_excluded_from_code(): void
    {
        $this->codeFieldFixed(null, 'nam', '2026', 1);
        $this->codeFieldFixed($this->teamA, 'cho_duyet', 'XXX', 2, 'pending');

        $lead = Lead::create([
            'received_date' => now()->toDateString(), 'name' => 'D', 'phone' => '0900000004',
            'org_unit_id' => $this->teamA->id,
        ]);
        $this->assertSame('KH-' . str_pad((string) $lead->id, 3, '0', STR_PAD_LEFT) . '-2026', $lead->generateCode());
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
        // Phase 6.20 — migration seed 2 field cấp công ty page+camp, cần lọc chỉ ma_gioi_thieu
        $companyField = $this->field(null, 'ma_gioi_thieu');
        $this->field($this->sales, 'nhu_cau');

        $applicable = CustomField::applicableTo(null);
        // Chỉ chọn ra field vừa tạo (bỏ qua page/camp mặc định của Phase 6.20)
        $this->assertTrue($applicable->contains('id', $companyField->id));
        $this->assertFalse($applicable->contains(fn ($f) => $f->key === 'nhu_cau'));
    }

    // ---------- Workflow duyệt ----------

    public function test_pending_field_not_applied_until_approved(): void
    {
        $pending = $this->field($this->teamA, 'cho_duyet', ['status' => 'pending', 'required' => true]);

        // Chưa duyệt → không áp lên lead
        $this->assertFalse(CustomField::applicableTo($this->teamA)->contains('id', $pending->id));

        // Duyệt → áp ngay
        $pending->update(['status' => 'active']);
        $this->assertTrue(CustomField::applicableTo($this->teamA)->contains('id', $pending->id));
    }

    public function test_rejected_field_never_applied(): void
    {
        $rejected = $this->field($this->teamA, 'bi_tu_choi', ['status' => 'rejected', 'required' => true]);

        $this->assertFalse(CustomField::applicableTo($this->teamA)->contains('id', $rejected->id));
    }
}

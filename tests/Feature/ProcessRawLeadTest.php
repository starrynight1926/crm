<?php

namespace Tests\Feature;

use App\Jobs\ProcessRawLead;
use App\Models\Lead;
use App\Models\LeadStatusLog;
use App\Models\RawLead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessRawLeadTest extends TestCase
{
    use RefreshDatabase;

    private function makeRaw(array $payload, array $attrs = []): RawLead
    {
        return RawLead::create(array_merge([
            'source_type' => RawLead::SOURCE_WEBHOOK,
            'payload' => $payload,
            'status' => RawLead::STATUS_PENDING,
            'created_at' => now(),
        ], $attrs));
    }

    private function process(RawLead $raw): RawLead
    {
        (new ProcessRawLead($raw->id))->handle();

        return $raw->fresh();
    }

    public function test_valid_payload_creates_clean_lead(): void
    {
        $raw = $this->makeRaw([
            'name' => 'Nguyễn Văn Pipeline',
            'phone' => '+84 912 345 678',
            'camp' => 'Camp T7',
            'ad_source' => 'Facebook Ads',
            'received_date' => '01/07/2026',
        ]);

        $raw = $this->process($raw);

        $this->assertSame(RawLead::STATUS_PROCESSED, $raw->status);
        $lead = Lead::find($raw->clean_lead_id);
        $this->assertNotNull($lead);
        $this->assertSame('0912345678', $lead->phone); // đã chuẩn hóa
        $this->assertSame('2026-07-01', $lead->received_date->toDateString()); // parse d/m/Y
        $this->assertSame($raw->id, $lead->raw_lead_id); // truy vết ngược
        // Không có classification field cấu hình → mã core trần KH-{id}
        $this->assertSame('KH-' . str_pad((string) $lead->id, 3, '0', STR_PAD_LEFT), $lead->code);
        $this->assertSame(Lead::POOL_COMMON, $lead->pool_level); // vào kho chung
        $this->assertTrue(LeadStatusLog::where('lead_id', $lead->id)->where('field', 'created')->exists());
    }

    public function test_invalid_phone_marks_failed_with_reason(): void
    {
        $raw = $this->process($this->makeRaw(['name' => 'A', 'phone' => '12345']));

        $this->assertSame(RawLead::STATUS_FAILED, $raw->status);
        $this->assertStringContainsString('SĐT không hợp lệ', $raw->error_reason);
        $this->assertSame(0, Lead::count());
    }

    public function test_missing_name_marks_failed(): void
    {
        $raw = $this->process($this->makeRaw(['phone' => '0901234567']));

        $this->assertSame(RawLead::STATUS_FAILED, $raw->status);
        $this->assertStringContainsString('Thiếu tên', $raw->error_reason);
    }

    public function test_missing_phone_marks_failed(): void
    {
        $raw = $this->process($this->makeRaw(['name' => 'A']));

        $this->assertSame(RawLead::STATUS_FAILED, $raw->status);
        $this->assertStringContainsString('Thiếu số điện thoại', $raw->error_reason);
    }

    public function test_duplicate_phone_merges_into_existing_lead(): void
    {
        $existing = Lead::create([
            'received_date' => now()->toDateString(),
            'name' => 'Khách cũ',
            'phone' => '0901234567',
            'camp' => 'Camp cũ',
        ]);

        $raw = $this->process($this->makeRaw([
            'name' => 'Khách trùng',
            'phone' => '090 123 4567',
            'camp' => 'Camp mới',   // đã có → không ghi đè
            'region' => 'Hà Nội',   // còn trống → gộp vào
        ]));

        $this->assertSame(RawLead::STATUS_DUPLICATE, $raw->status);
        $this->assertSame($existing->id, $raw->clean_lead_id);
        $this->assertSame(1, Lead::count()); // không tạo lead mới

        $existing->refresh();
        $this->assertSame('Camp cũ', $existing->camp);   // giữ nguyên
        $this->assertSame('Hà Nội', $existing->region);  // được gộp
        $this->assertTrue(
            LeadStatusLog::where('lead_id', $existing->id)
                ->where('new_value', 'like', '%đã gộp thêm: region%')->exists()
        );
    }

    public function test_already_processed_raw_is_skipped(): void
    {
        $raw = $this->makeRaw(['name' => 'A', 'phone' => '0901234567'], ['status' => RawLead::STATUS_FAILED, 'error_reason' => 'x']);

        $raw = $this->process($raw);

        $this->assertSame(RawLead::STATUS_FAILED, $raw->status);
        $this->assertSame(0, Lead::count());
    }
}

<?php

namespace App\Jobs;

use App\Models\Lead;
use App\Models\LeadStatusLog;
use App\Models\RawLead;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Pipeline chuẩn hóa raw → clean (scope.md mục 3):
 * validate/chuẩn hóa SĐT → check trùng (trùng thì gộp, không chia mới) → ghi MySQL kèm raw_lead_id.
 */
class ProcessRawLead implements ShouldQueue
{
    use Queueable;

    /** Retry khi deadlock MySQL lúc nhiều worker chia số cùng lúc (job idempotent). */
    public int $tries = 3;

    public array $backoff = [1, 5];

    public function __construct(public int $rawLeadId)
    {
    }

    public function handle(): void
    {
        $raw = RawLead::find($this->rawLeadId);

        if (! $raw) {
            return;
        }

        // Retry sau deadlock: lead đã tạo nhưng chia số dở dang → chia tiếp thay vì bỏ qua
        if ($raw->status === RawLead::STATUS_PROCESSED && $raw->clean_lead_id) {
            $lead = Lead::find($raw->clean_lead_id);
            if ($lead && $lead->pool_level !== Lead::POOL_PERSONAL) {
                app(\App\Services\DistributionEngine::class)->distribute($lead);
            }

            return;
        }

        if ($raw->status !== RawLead::STATUS_PENDING) {
            return; // failed / duplicate — đã xử lý
        }

        $payload = $raw->payload ?? [];

        // --- Validate ---
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            $this->fail_($raw, 'Thiếu tên khách hàng.');
            return;
        }

        $rawPhone = trim((string) ($payload['phone'] ?? ''));
        if ($rawPhone === '') {
            $this->fail_($raw, 'Thiếu số điện thoại.');
            return;
        }

        $phone = Lead::normalizePhone($rawPhone);
        if ($phone === null) {
            $this->fail_($raw, "SĐT không hợp lệ: \"{$rawPhone}\".");
            return;
        }

        // --- Check trùng: gộp vào lead cũ, không tạo mới ---
        $existing = Lead::where('phone', $phone)->first();
        if ($existing) {
            $this->mergeInto($existing, $raw, $payload);
            return;
        }

        // --- Tạo lead sạch ---
        $lead = Lead::create([
            'raw_lead_id' => $raw->id,
            'received_date' => $this->parseDate($payload['received_date'] ?? null) ?? $raw->created_at?->toDateString() ?? now()->toDateString(),
            'name' => $name,
            'phone' => $phone,
            'page' => $payload['page'] ?? null,
            'camp' => $payload['camp'] ?? null,
            'insight' => $payload['insight'] ?? null,
            'link' => $payload['link'] ?? null,
            'ad_source' => $payload['ad_source'] ?? null,
            'region' => $payload['region'] ?? null,
            'note' => $payload['note'] ?? null,
            'classification' => 'new',
            'pool_level' => Lead::POOL_COMMON, // vào kho chung, chờ engine chia số (Phase 4)
        ]);
        $lead->generateCode();

        LeadStatusLog::record($lead, 'created', null, 'Pipeline từ nguồn ' . $raw->source_type . ($raw->source_ref ? " ({$raw->source_ref})" : ''), null);

        $raw->update([
            'status' => RawLead::STATUS_PROCESSED,
            'clean_lead_id' => $lead->id,
            'processed_at' => now(),
        ]);

        // Lead sạch vào kho chung → engine chia số chạy ngay (không phụ thuộc giờ làm việc)
        app(\App\Services\DistributionEngine::class)->distribute($lead);
    }

    /** Gộp thông tin mới vào lead cũ: chỉ điền field còn trống, log lại. */
    private function mergeInto(Lead $existing, RawLead $raw, array $payload): void
    {
        $merged = [];
        foreach (['page', 'camp', 'insight', 'link', 'ad_source', 'region', 'note'] as $field) {
            $value = $payload[$field] ?? null;
            if ($value && ! $existing->{$field}) {
                $existing->{$field} = $value;
                $merged[] = $field;
            }
        }

        if ($merged !== []) {
            $existing->save();
        }

        LeadStatusLog::record(
            $existing,
            'note',
            null,
            'Lead về trùng SĐT từ nguồn ' . $raw->source_type
                . ($merged !== [] ? ' — đã gộp thêm: ' . implode(', ', $merged) : ' — không có thông tin mới'),
            null
        );

        $raw->update([
            'status' => RawLead::STATUS_DUPLICATE,
            'clean_lead_id' => $existing->id,
            'error_reason' => 'Trùng SĐT với lead #' . $existing->id . ' — đã gộp.',
            'processed_at' => now(),
        ]);
    }

    private function fail_(RawLead $raw, string $reason): void
    {
        $raw->update([
            'status' => RawLead::STATUS_FAILED,
            'error_reason' => $reason,
            'processed_at' => now(),
        ]);
    }

    private function parseDate(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'm/d/Y'] as $format) {
            try {
                return \Carbon\Carbon::createFromFormat($format, trim($value))->toDateString();
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }
}

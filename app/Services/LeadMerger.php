<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\LeadStatusLog;
use Illuminate\Support\Facades\DB;

/**
 * Phase 6.27.f — Gộp lead "missing info" vào lead gốc.
 *
 * Flow: dồn toàn bộ child-rows (booking/call/log/custom/payment/...) từ clone → root,
 * append note clone vào note root, audit log, rồi HARD DELETE clone (forceDelete).
 * Chạy trong transaction — fail nửa chừng là rollback hết.
 *
 * Caller chịu trách nhiệm:
 *  - Guard permission (lead.access_no_phone_pool)
 *  - Guard visibility: clone + root đều isVisibleTo user hiện tại
 *  - Confirm trên UI
 */
class LeadMerger
{
    /**
     * Các bảng con có lead_id FK đơn giản (chỉ update lead_id → root).
     * KHÔNG include lead_custom_values + lead_phase_closures (có unique collision).
     */
    private const SIMPLE_CHILD_TABLES = [
        'audit_logs',
        'booking_logs',
        'booking_late_logs',
        'call_logs',
        'contributions',
        'customer_services',
        'lead_distribution_logs',
        'lead_ownership_history',
        'lead_status_logs',
        'lead_treatments',
        'lead_upsells',
        'payments',
    ];

    public function merge(Lead $clone, Lead $root): array
    {
        if ($clone->id === $root->id) {
            return ['ok' => false, 'error' => 'Clone và root là cùng 1 lead.'];
        }
        if (! $clone->no_phone) {
            return ['ok' => false, 'error' => 'Chỉ gộp lead thiếu SĐT (no_phone=true). Lead ' . $clone->code . ' có SĐT.'];
        }
        if ($root->trashed()) {
            return ['ok' => false, 'error' => 'Lead gốc đã bị xóa — không gộp được.'];
        }

        $summary = [];
        $cloneId = $clone->id;
        $rootId = $root->id;
        $cloneCode = $clone->code ?: ('#' . $clone->id);

        try {
            DB::transaction(function () use ($clone, $root, $cloneId, $rootId, $cloneCode, &$summary) {
                // 1. Simple tables — reassign lead_id.
                foreach (self::SIMPLE_CHILD_TABLES as $table) {
                    $n = DB::table($table)->where('lead_id', $cloneId)->update(['lead_id' => $rootId]);
                    if ($n > 0) $summary[$table] = $n;
                }

                // 2. lead_custom_values — PK (lead_id, custom_field_id). Giữ của root nếu trùng field.
                $moved = 0;
                $skipped = 0;
                $existingFieldIds = DB::table('lead_custom_values')
                    ->where('lead_id', $rootId)
                    ->pluck('custom_field_id')
                    ->all();
                $cloneRows = DB::table('lead_custom_values')->where('lead_id', $cloneId)->get();
                foreach ($cloneRows as $row) {
                    if (in_array($row->custom_field_id, $existingFieldIds, true)) {
                        DB::table('lead_custom_values')
                            ->where('lead_id', $cloneId)
                            ->where('custom_field_id', $row->custom_field_id)
                            ->delete();
                        $skipped++;
                    } else {
                        DB::table('lead_custom_values')
                            ->where('lead_id', $cloneId)
                            ->where('custom_field_id', $row->custom_field_id)
                            ->update(['lead_id' => $rootId]);
                        $moved++;
                    }
                }
                if ($moved) $summary['lead_custom_values'] = $moved;
                if ($skipped) $summary['lead_custom_values_skipped'] = $skipped;

                // 3. lead_phase_closures — unique (lead_id, phase). Giữ của root nếu trùng phase.
                $moved = 0;
                $skipped = 0;
                $existingPhases = DB::table('lead_phase_closures')
                    ->where('lead_id', $rootId)
                    ->pluck('phase')
                    ->all();
                $cloneRows = DB::table('lead_phase_closures')->where('lead_id', $cloneId)->get();
                foreach ($cloneRows as $row) {
                    if (in_array($row->phase, $existingPhases, true)) {
                        DB::table('lead_phase_closures')
                            ->where('lead_id', $cloneId)
                            ->where('phase', $row->phase)
                            ->delete();
                        $skipped++;
                    } else {
                        DB::table('lead_phase_closures')
                            ->where('lead_id', $cloneId)
                            ->where('phase', $row->phase)
                            ->update(['lead_id' => $rootId]);
                        $moved++;
                    }
                }
                if ($moved) $summary['lead_phase_closures'] = $moved;
                if ($skipped) $summary['lead_phase_closures_skipped'] = $skipped;

                // 4. Note merge: append clone.note vào root.note nếu có.
                if (trim((string) $clone->note) !== '') {
                    $prefix = "[Gộp từ {$cloneCode}]: " . $clone->note;
                    $root->note = $root->note
                        ? $prefix . "\n\n" . $root->note
                        : $prefix;
                    $root->save();
                }

                // 5. Audit + status log.
                AuditLog::record('merge_leads', $root, [
                    'merged_from_id' => $cloneId,
                    'merged_from_code' => $cloneCode,
                    'summary' => $summary,
                ]);
                LeadStatusLog::record(
                    $root,
                    'merge_leads',
                    null,
                    "Gộp từ lead thiếu info {$cloneCode}: " . json_encode($summary, JSON_UNESCAPED_UNICODE),
                    auth()->id()
                );

                // 6. Hard delete clone.
                Lead::whereKey($cloneId)->forceDelete();
            });

            return ['ok' => true, 'summary' => $summary];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Gộp fail: ' . $e->getMessage()];
        }
    }

    /**
     * Preview counts — gọi trước confirm merge để hiện cho user.
     */
    public function preview(Lead $clone): array
    {
        $counts = [];
        foreach (self::SIMPLE_CHILD_TABLES as $table) {
            $n = DB::table($table)->where('lead_id', $clone->id)->count();
            if ($n > 0) $counts[$table] = $n;
        }
        $counts['lead_custom_values'] = DB::table('lead_custom_values')->where('lead_id', $clone->id)->count();
        $counts['lead_phase_closures'] = DB::table('lead_phase_closures')->where('lead_id', $clone->id)->count();
        return array_filter($counts);
    }
}

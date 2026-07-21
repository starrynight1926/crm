<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Tính stats_daily (ERD B7). Idempotent: xóa dòng của ngày rồi ghi lại.
 * - Funnel: lead nhận trong ngày (received_date), đếm theo classification HIỆN TẠI,
 *   chiều = (org_unit, owner, camp).
 * - Revenue: payments theo paid_at, user = người thu, các chiều còn lại theo lead.
 */
class StatsAggregator
{
    public function aggregateDay(string $date): void
    {
        DB::transaction(function () use ($date) {
            DB::table('stats_daily')->where('date', $date)->delete();

            $rows = [];

            // Phase 6.21 — camp là custom field cấp phòng Marketing (nhiều field), JOIN theo key
            $campFieldIds = DB::table('custom_fields')->where('key', 'camp')->pluck('id')->all();

            // --- Funnel từ leads ---
            $funnel = DB::table('leads')
                ->leftJoin('lead_custom_values as camp_cv', function ($join) use ($campFieldIds) {
                    $join->on('camp_cv.lead_id', '=', 'leads.id')->whereIn('camp_cv.custom_field_id', $campFieldIds ?: [0]);
                })
                ->whereDate('received_date', $date)
                ->whereNull('deleted_at')
                ->selectRaw("leads.org_unit_id, leads.owner_id, camp_cv.value as camp,
                    count(*) as total,
                    sum(classification = 'lead') as `lead`,
                    sum(classification = 'follow') as `follow`,
                    sum(classification = 'net') as net,
                    sum(classification = 'booking') as booking,
                    sum(classification = 'show') as `show`,
                    sum(classification = 'close') as `close`")
                ->groupBy('leads.org_unit_id', 'leads.owner_id', 'camp_cv.value')
                ->get();

            foreach ($funnel as $r) {
                $rows[$this->key($date, $r->org_unit_id, $r->owner_id, $r->camp)] = [
                    'date' => $date,
                    'org_unit_id' => $r->org_unit_id,
                    'user_id' => $r->owner_id,
                    'camp' => $r->camp,
                    'total' => (int) $r->total,
                    'lead' => (int) $r->lead,
                    'follow' => (int) $r->follow,
                    'net' => (int) $r->net,
                    'booking' => (int) $r->booking,
                    'show' => (int) $r->show,
                    'close' => (int) $r->close,
                    'revenue_collected' => 0,
                ];
            }

            // --- Revenue từ payments (user = người thu) ---
            $revenue = DB::table('payments')
                ->join('leads', 'leads.id', '=', 'payments.lead_id')
                ->leftJoin('lead_custom_values as camp_cv', function ($join) use ($campFieldIds) {
                    $join->on('camp_cv.lead_id', '=', 'leads.id')->whereIn('camp_cv.custom_field_id', $campFieldIds ?: [0]);
                })
                ->whereDate('payments.paid_at', $date)
                ->selectRaw('leads.org_unit_id, payments.collected_by as user_id, camp_cv.value as camp, sum(payments.amount) as amount')
                ->groupBy('leads.org_unit_id', 'payments.collected_by', 'camp_cv.value')
                ->get();

            foreach ($revenue as $r) {
                $key = $this->key($date, $r->org_unit_id, $r->user_id, $r->camp);
                if (isset($rows[$key])) {
                    $rows[$key]['revenue_collected'] = (int) $r->amount;
                } else {
                    $rows[$key] = [
                        'date' => $date,
                        'org_unit_id' => $r->org_unit_id,
                        'user_id' => $r->user_id,
                        'camp' => $r->camp,
                        'total' => 0, 'lead' => 0, 'follow' => 0, 'net' => 0,
                        'booking' => 0, 'show' => 0, 'close' => 0,
                        'revenue_collected' => (int) $r->amount,
                    ];
                }
            }

            foreach (array_chunk(array_values($rows), 500) as $chunk) {
                DB::table('stats_daily')->insert($chunk);
            }
        });
    }

    private function key(string $date, $org, $user, $camp): string
    {
        return implode('|', [$date, $org ?? '-', $user ?? '-', $camp ?? '-']);
    }
}

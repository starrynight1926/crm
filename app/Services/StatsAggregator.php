<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Tính stats_daily (ERD B7). Idempotent: xóa dòng của ngày rồi ghi lại.
 * - Funnel: lead nhận trong ngày (received_date), đếm theo classification HIỆN TẠI,
 *   chiều = (org_unit, owner, camp, ad_source).
 * - Revenue: payments theo paid_at, user = người thu, các chiều còn lại theo lead.
 */
class StatsAggregator
{
    public function aggregateDay(string $date): void
    {
        DB::transaction(function () use ($date) {
            DB::table('stats_daily')->where('date', $date)->delete();

            $rows = [];

            // --- Funnel từ leads ---
            $funnel = DB::table('leads')
                ->whereDate('received_date', $date)
                ->whereNull('deleted_at')
                ->selectRaw("org_unit_id, owner_id, camp, ad_source,
                    count(*) as total,
                    sum(classification = 'lead') as `lead`,
                    sum(classification = 'follow') as `follow`,
                    sum(classification = 'net') as net,
                    sum(classification = 'booking') as booking,
                    sum(classification = 'show') as `show`,
                    sum(classification = 'close') as `close`")
                ->groupBy('org_unit_id', 'owner_id', 'camp', 'ad_source')
                ->get();

            foreach ($funnel as $r) {
                $rows[$this->key($date, $r->org_unit_id, $r->owner_id, $r->camp, $r->ad_source)] = [
                    'date' => $date,
                    'org_unit_id' => $r->org_unit_id,
                    'user_id' => $r->owner_id,
                    'camp' => $r->camp,
                    'ad_source' => $r->ad_source,
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
                ->whereDate('payments.paid_at', $date)
                ->selectRaw('leads.org_unit_id, payments.collected_by as user_id, leads.camp, leads.ad_source, sum(payments.amount) as amount')
                ->groupBy('leads.org_unit_id', 'payments.collected_by', 'leads.camp', 'leads.ad_source')
                ->get();

            foreach ($revenue as $r) {
                $key = $this->key($date, $r->org_unit_id, $r->user_id, $r->camp, $r->ad_source);
                if (isset($rows[$key])) {
                    $rows[$key]['revenue_collected'] = (int) $r->amount;
                } else {
                    $rows[$key] = [
                        'date' => $date,
                        'org_unit_id' => $r->org_unit_id,
                        'user_id' => $r->user_id,
                        'camp' => $r->camp,
                        'ad_source' => $r->ad_source,
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

    private function key(string $date, $org, $user, $camp, $source): string
    {
        return implode('|', [$date, $org ?? '-', $user ?? '-', $camp ?? '-', $source ?? '-']);
    }
}

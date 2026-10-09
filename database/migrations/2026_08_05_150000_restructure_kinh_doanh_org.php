<?php

use App\Models\OrgUnit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 2026-08-05 — Restructure org tree per user chốt:
 *   - Marketing chỉ chứa Team Nhập Lead.
 *   - Kinh Doanh (NEW, song song Marketing) chứa Phòng Kinh Doanh 1/2 (rename từ team-giang / team-hoi-hn / team-ashley).
 *   - ĐN đồng dạng: tạo Kinh Doanh + team-dn (PKD1), move team-dn-booking/sale làm con.
 *
 * Rename in-place (giữ org_unit.id) — assignments/leads/notifications không đổi FK.
 * Rebuild path atomic cho subtree bị move.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            foreach ([
                ['branch-hn',  'kinh-doanh-hn',  [
                    ['old' => 'team-giang',   'name' => 'Phòng Kinh Doanh 1'],
                    ['old' => 'team-hoi-hn',  'name' => 'Phòng Kinh Doanh 2'],
                ]],
                ['branch-hcm', 'kinh-doanh-hcm', [
                    ['old' => 'team-ashley',  'name' => 'Phòng Kinh Doanh 1'],
                ]],
            ] as [$branchCode, $kdCode, $moves]) {
                $branch = OrgUnit::firstWhere('code', $branchCode);
                if (! $branch) continue;

                $kd = OrgUnit::firstWhere('code', $kdCode)
                    ?? OrgUnit::createNode(['code' => $kdCode, 'name' => 'Kinh Doanh'], $branch);

                foreach ($moves as $m) {
                    $node = OrgUnit::firstWhere('code', $m['old']);
                    if (! $node) continue;
                    $node->name = $m['name'];
                    $this->moveUnder($node, $kd);
                }
            }

            // ĐN: tạo Kinh Doanh + PKD1, move team-dn-booking/sale làm con của PKD1.
            $dn = OrgUnit::firstWhere('code', 'branch-dn');
            if ($dn) {
                $kdDn = OrgUnit::firstWhere('code', 'kinh-doanh-dn')
                    ?? OrgUnit::createNode(['code' => 'kinh-doanh-dn', 'name' => 'Kinh Doanh'], $dn);
                $pkdDn = OrgUnit::firstWhere('code', 'team-dn')
                    ?? OrgUnit::createNode(['code' => 'team-dn', 'name' => 'Phòng Kinh Doanh 1'], $kdDn);

                foreach (['team-dn-booking', 'team-dn-sale'] as $childCode) {
                    $child = OrgUnit::firstWhere('code', $childCode);
                    if ($child) $this->moveUnder($child, $pkdDn);
                }
            }
        });
    }

    public function down(): void
    {
        // Không auto-rollback (rủi ro cao khi assignments/leads đã dùng path mới).
        // Muốn rollback: khôi phục từ backup DB.
    }

    /** Move $node sang parent mới + rebuild path/depth cho toàn subtree. */
    private function moveUnder(OrgUnit $node, OrgUnit $newParent): void
    {
        $oldPath = $node->path;
        $node->parent_id = $newParent->id;
        $node->depth = $newParent->depth + 1;
        $node->path = rtrim($newParent->path, '/') . '/' . $node->id . '/';
        $node->save();

        // Rebuild path/depth cho toàn subtree (children/grandchildren).
        $descendants = OrgUnit::where('path', 'like', $oldPath . '%')
            ->where('id', '!=', $node->id)
            ->get();
        foreach ($descendants as $d) {
            $d->path = str_replace($oldPath, $node->path, $d->path);
            $d->depth = substr_count(trim($d->path, '/'), '/') + 0;
            $d->save();
        }
    }
};

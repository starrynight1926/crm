<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-09-23 — Danh sách UPS List do admin tick tay theo TỪNG cơ sở (facility_pool_unit),
 * thay cho heuristic match tên role (%ale%/%eader%/Trợ lý) trong ⚡ups-board.blade.php
 * saleUsersOfFacility(). Lý do: phát sinh case sale chưa đủ điều kiện / lead chưa đủ chuẩn
 * không được vào UPS — cần admin chốt tay, không suy luận qua tên role nữa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ups_list_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facility_pool_unit_id')->constrained('pool_units')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['facility_pool_unit_id', 'user_id']);
        });

        // Backfill: seed từ heuristic role-name cũ để danh sách không trống ngay sau migrate.
        // Admin (super admin) sẽ vào /settings/ups-list rà lại, bỏ người chưa đủ điều kiện.
        $facilities = DB::table('pool_units')->where('kind', 'facility')->get(['id']);
        $now = now();
        $rows = [];

        foreach ($facilities as $facility) {
            $orgIds = DB::table('org_pool_map')->where('pool_unit_id', $facility->id)->pluck('org_unit_id')->all();
            if (! $orgIds) {
                continue;
            }

            $orgs = DB::table('org_units')->whereIn('id', $orgIds)->get(['id', 'path']);
            $subtreeIds = [];
            foreach ($orgs as $org) {
                // path đã có dạng "/a/b/id/" (tự chứa id của chính nó) — like path% ăn cả node này + con cháu.
                $descendantIds = DB::table('org_units')->where('path', 'like', $org->path . '%')->pluck('id')->all();
                $subtreeIds = array_merge($subtreeIds, $descendantIds);
            }
            $subtreeIds = array_unique($subtreeIds);
            if (! $subtreeIds) {
                continue;
            }

            $userIds = DB::table('assignments')
                ->join('roles', 'assignments.role_id', '=', 'roles.id')
                ->whereIn('assignments.org_unit_id', $subtreeIds)
                ->where(function ($q) {
                    $q->where('roles.name', 'like', '%ale%')
                        ->orWhere('roles.name', 'like', '%eader%')
                        ->orWhere('roles.name', 'like', '%Trợ lý%');
                })
                ->distinct()
                ->pluck('assignments.user_id');

            foreach ($userIds as $userId) {
                $rows[] = [
                    'facility_pool_unit_id' => $facility->id,
                    'user_id' => $userId,
                    'added_by' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($rows) {
            DB::table('ups_list_members')->insertOrIgnore($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ups_list_members');
    }
};

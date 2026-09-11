<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 2026-08-17 — Revoke source.up.hl khỏi các role Sale.
 *
 * Hotline (HL) là nguồn chỉ Admin được up (theo scope.md).
 * Trước bug: seed cũ grant nhầm cho Sale/Team sale/Team sale ĐN → Sale bucket A
 * thấy Hotline trong dropdown nguồn.
 *
 * Admin không cần grant riêng — đã có lead.source_all bypass toàn bộ SOURCE_PERMISSIONS.
 */
return new class extends Migration
{
    private const TARGET_ROLES = ['Sale', 'Team sale', 'Team sale ĐN'];

    public function up(): void
    {
        $permId = DB::table('permissions')->where('key', 'source.up.hl')->value('id');
        if (! $permId) {
            return;
        }
        $roleIds = DB::table('roles')->whereIn('name', self::TARGET_ROLES)->pluck('id');
        DB::table('permission_role')
            ->whereIn('role_id', $roleIds)
            ->where('permission_id', $permId)
            ->delete();
    }

    public function down(): void
    {
        // Không tự grant lại — rollback thủ công nếu thật sự cần.
    }
};

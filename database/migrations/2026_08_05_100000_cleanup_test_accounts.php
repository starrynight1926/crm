<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 2026-08-05 (T12): dọn tài khoản test theo yêu cầu user:
 *   - Xóa: cmbktg@ (CM Booking Team Giang), cmsale@ (CM Sale),
 *          cmsale_giang@ (CM Sale Team Giang), cmbooking_ashley@ (CM Booking Team Ashley).
 *   - Rename: book1 → "Tài khoản Booking", book2 → "Tài khoản Sale Booking".
 *
 * Idempotent — chạy lại lần 2 không lỗi (delete nếu tồn tại, update by email).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Match theo cả email cũ VÀ name — vì RenameUsersToPositionFormatSeeder
        // đã đổi email sang format hn.cmb01, hcm.cmb01, hn.book01, hn.book02...
        $emailsToDelete = [
            'cmbktg@longevity.com.vn',
            'cmsale@longevity.com.vn',
            'cmsale_giang@longevity.com.vn',
            'cmbooking_ashley@longevity.com.vn',
            // 2026-08-05: bỏ NV Marketing + NV Kinh Doanh — Marketing chỉ dùng Trực Page, KD dùng tài khoản CM/Sale thật.
            'nvkd@longevity.com.vn',
            'nvmkt@longevity.com.vn',
        ];
        $namesToDelete = [
            'CM Booking Team Giang',
            'CM Sale',
            'CM Sale Team Giang',
            'CM Booking Team Ashley',
            'NV Kinh Doanh',
            'NV Marketing',
        ];

        $userIds = DB::table('users')
            ->whereIn('email', $emailsToDelete)
            ->orWhereIn('name', $namesToDelete)
            ->pluck('id')->all();

        if (! empty($userIds)) {
            DB::table('assignments')->whereIn('user_id', $userIds)->delete();
            DB::table('leads')->whereIn('owner_id', $userIds)->update(['owner_id' => null]);
            DB::table('leads')->whereIn('receiver_id', $userIds)->update(['receiver_id' => null]);
            DB::table('users')->whereIn('id', $userIds)->delete();
        }

        // Rename book1 / book2 — match qua name cũ vì email đã bị rename.
        DB::table('users')
            ->where(fn ($q) => $q->where('email', 'book1@longevity.com.vn')->orWhere('name', 'Nguyễn Booking 1'))
            ->update(['name' => 'Tài khoản Booking']);
        DB::table('users')
            ->where(fn ($q) => $q->where('email', 'book2@longevity.com.vn')->orWhere('name', 'Trần Booking 2'))
            ->update(['name' => 'Tài khoản Sale Booking']);
    }

    public function down(): void
    {
        // Không rollback — không đảo được (data user đã xóa vĩnh viễn).
        // Muốn khôi phục: chạy `db:seed --class=OrgStaffSeeder` sau khi revert seeder trong git.
    }
};

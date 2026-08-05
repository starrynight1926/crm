<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class SyncCrmAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        // ---------- Phần 1: Backfill username cho user CRM đã có ----------
        // Map: email hiện tại -> username (khớp với booking cho user trùng người,
        // còn lại lấy prefix email).
        $usernameByEmail = [
            'admin@longevity.com.vn'    => 'admin',
            'huyently@longevity.com.vn' => 'huyently',
            'hangktt@longevity.com.vn'  => 'hangktt',
            'lyktdt@longevity.com.vn'   => 'lyktdt',
            'msan@longevity.com.vn'     => 'msan',
            'mstuyet@longevity.com.vn'  => 'mstuyet',
            'baoit@longevity.com.vn'    => 'baoit',
            'tumod@longevity.com.vn'    => 'tumod',
            'ltkp@longevity.com.vn'     => 'ltkp',
            'ttg@longevity.com.vn'      => 'tttg',
            'tvh@longevity.com.vn'      => 'tvh',
            'nhd@longevity.com.vn'      => 'nhd',
            'tnkn@longevity.com.vn'     => 'tnkn',
            'ptkq@longevity.com.vn'     => 'ptkq',
            'tbt@longevity.com.vn'      => 'ttbt',
            'nmt@longevity.com.vn'      => 'ntmt',
            'hbtl@longevity.com.vn'     => 'hbtl',
            'lpt@longevity.com.vn'      => 'ltpt',
            'thk@longevity.com.vn'      => 'thk',
            'nhg@longevity.com.vn'      => 'nhg',
            'nmp@longevity.com.vn'      => 'nmp',
            'nta@longevity.com.vn'      => 'nta',
            'ntn@longevity.com.vn'      => 'ntn',
            'cla@longevity.com.vn'      => 'ctla',
            'ptt@longevity.com.vn'      => 'ptt',
            'ntt@longevity.com.vn'      => 'ntt',
            'pta@longevity.com.vn'      => 'pta',
            'ntm@longevity.com.vn'      => 'ntm',
            'nma@longevity.com.vn'      => 'nma',
            'tyn@longevity.com.vn'      => 'ttyn',
            'nhn@longevity.com.vn'      => 'nthn',
            'hmm@longevity.com.vn'      => 'htmm',
            'ntt2@longevity.com.vn'     => 'ntth',
            'nkc@longevity.com.vn'      => 'ntkc',
            'lpd@longevity.com.vn'      => 'lpd',
            'page1@longevity.com.vn'    => 'page1',
            'cmbktg@longevity.com.vn'   => 'cmbktg',
            'book1@longevity.com.vn'    => 'book1',
            'book2@longevity.com.vn'    => 'book2',
            'cmsale@longevity.com.vn'   => 'cmsale',
        ];

        $backfilled = 0;
        foreach ($usernameByEmail as $email => $username) {
            $affected = DB::table('users')
                ->where('email', $email)
                ->update(['username' => $username, 'updated_at' => $now]);
            if ($affected > 0) $backfilled++;
        }

        // ---------- Phần 2: DỌN user booking-only ----------
        // 2026-08-05: Bỏ khối "bookingOnly" (29 user: ktv_*/dd_*/ddt_*/bsi*/adminvh).
        // Chúng là mirror data từ sbooking (KTV/ĐD/BS phòng khám) — scrm KHÔNG dùng
        // (không có assignment, không lead, không role). Chỉ làm rác trong /admin/catalog.
        // Nếu cần đồng bộ nhân sự phòng khám → dùng sb_bac_si (mirror sync) thay vì
        // clone vào users.
        $legacyPrefixes = ['ktv\_', 'dd\_', 'ddt\_', 'bsi', 'adminvh'];
        $legacyIds = DB::table('users')
            ->where(function ($q) use ($legacyPrefixes) {
                foreach ($legacyPrefixes as $p) {
                    $q->orWhere('username', 'like', $p . '%');
                }
            })
            ->pluck('id');
        $deleted = 0;
        if ($legacyIds->isNotEmpty()) {
            DB::table('assignments')->whereIn('user_id', $legacyIds)->delete();
            $deleted = DB::table('users')->whereIn('id', $legacyIds)->delete();
        }

        $this->command->info("Backfilled username: {$backfilled}/" . count($usernameByEmail));
        $this->command->info("Đã xoá {$deleted} user legacy (ktv_/dd_/ddt_/bsi/adminvh).");
    }
}

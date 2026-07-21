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
            'admin@longevity.com.vn'    => 'admincrm',
            'nvkd@longevity.com.vn'     => 'nvkd',
            'nvmkt@longevity.com.vn'    => 'nvmkt',
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

        // ---------- Phần 2: Thêm 26 user booking-only vào CRM ----------
        // Password giữ nguyên như booking:
        //   - 59ntn (KTV/ĐD/team hỗ trợ) + adminvh -> '59@ntn'
        //   - 207nvt (KTV/ĐD)                       -> '207nvt'
        //   - BS chung (bsi59ntn, bsi207nvt)         -> 'bacsi'
        // Chưa gán role/org — admin sẽ gán sau qua UI.
        $pw59  = Hash::make('59@ntn');
        $pw207 = Hash::make('207nvt');
        $pwBs  = Hash::make('bacsi');

        $bookingOnly = [
            ['username' => 'adminvh',    'name' => 'Admin Vận hành',            'password' => $pw59],
            ['username' => 'ktv_viet',   'name' => 'Đỗ Ngọc Việt',              'password' => $pw59],
            ['username' => 'ktv_tu',     'name' => 'Nguyễn Thị Tú',             'password' => $pw59],
            ['username' => 'ktv_hoa',    'name' => 'Nguyễn Văn Hoà',            'password' => $pw59],
            ['username' => 'ktv_dong',   'name' => 'Đỗ Đức Đông',               'password' => $pw59],
            ['username' => 'ddt_trang',  'name' => 'Nguyễn Mạnh Tráng',         'password' => $pw59],
            ['username' => 'dd_thao',    'name' => 'Quản Thị Thảo',             'password' => $pw59],
            ['username' => 'dd_quynh',   'name' => 'Nguyễn Thị Diễm Quỳnh',     'password' => $pw59],
            ['username' => 'ddt_nhan',   'name' => 'Phạm Thị Thanh Nhàn',       'password' => $pw59],
            ['username' => 'dd_mi',      'name' => 'Trần Trà Mi',               'password' => $pw59],
            ['username' => 'ktv_tthao',  'name' => 'Trịnh Thị Thảo',            'password' => $pw59],
            ['username' => 'ktv_huong',  'name' => 'Đỗ Thu Hương',              'password' => $pw59],
            ['username' => 'ktv_phuong', 'name' => 'Nguyễn Thị Minh Phương',    'password' => $pw59],
            ['username' => 'ktv_bach',   'name' => 'Nguyễn Chí Bách',           'password' => $pw59],
            ['username' => 'ktv_vi',     'name' => 'Nguyễn Thị Lan Vi',         'password' => $pw59],
            ['username' => 'bsi59ntn',   'name' => 'Bác sĩ 59ntn',              'password' => $pwBs],
            ['username' => 'bsi207nvt',  'name' => 'Bác sĩ 207nvt',             'password' => $pwBs],
            ['username' => 'ktv_kieu',   'name' => 'Đàm Thúy Kiều',             'password' => $pw207],
            ['username' => 'ktv_gam',    'name' => 'Nguyễn Thị Hồng Gấm',       'password' => $pw207],
            ['username' => 'ktv_huyen',  'name' => 'Trần Thị Tú Huyên',         'password' => $pw207],
            ['username' => 'ktv_thuan',  'name' => 'Hoàng Hải Thuận',           'password' => $pw207],
            ['username' => 'ddt_loan',   'name' => 'Nguyễn Thị Thu Loan',       'password' => $pw207],
            ['username' => 'dd_tuan',    'name' => 'Hoàng Tam Tuấn',            'password' => $pw207],
            ['username' => 'dd_tien',    'name' => 'Nguyễn Thủy Tiên',          'password' => $pw207],
            ['username' => 'ktv_tan',    'name' => 'Nguyễn Thành Tân',          'password' => $pw207],
            ['username' => 'dd_thanh',   'name' => 'Khưu Thị Phương Thanh',     'password' => $pw207],
        ];

        $added = 0; $updatedPw = 0;
        foreach ($bookingOnly as $u) {
            $email = $u['username'] . '@longevity.com.vn';
            $existing = DB::table('users')->where('username', $u['username'])->first();

            if ($existing) {
                // Đã có (chạy lại seeder) -> chỉ đảm bảo password đúng.
                DB::table('users')->where('id', $existing->id)
                    ->update(['password' => $u['password'], 'updated_at' => $now]);
                $updatedPw++;
                continue;
            }

            DB::table('users')->insert([
                'username'   => $u['username'],
                'name'       => $u['name'],
                'email'      => $email,
                'password'   => $u['password'],
                'status'     => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $added++;
        }

        $this->command->info("Backfilled username: {$backfilled}/" . count($usernameByEmail));
        $this->command->info("Thêm mới: {$added} — cập nhật lại password: {$updatedPw}");
    }
}

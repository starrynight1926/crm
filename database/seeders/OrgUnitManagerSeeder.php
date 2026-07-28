<?php

namespace Database\Seeders;

use App\Models\OrgUnit;
use App\Models\User;
use Illuminate\Database\Seeder;

class OrgUnitManagerSeeder extends Seeder
{
    /**
     * Email cũ → Tên đầy đủ. Dùng làm fallback nếu email đã bị rename
     * sang format vị trí (hn.sale01@...) — match lại qua tên.
     */
    private const EMAIL_TO_NAME = [
        'mstuyet@longevity.com.vn' => 'Tuyết',
        'msan@longevity.com.vn'    => 'An',
        'tnkn@longevity.com.vn'    => 'Trần Nguyễn Kim Ngân',
        'ltkp@longevity.com.vn'    => 'Lương Thị Kim Phấn',
        'ttg@longevity.com.vn'     => 'Trần Thị Thu Giang',
        'tvh@longevity.com.vn'     => 'Tạ Văn Hợi',
        'nhd@longevity.com.vn'     => 'Nguyễn Hoành Đức',
        'tbt@longevity.com.vn'     => 'Trần Thị Bích Trâm',
        'hbtl@longevity.com.vn'    => 'Huỳnh Bùi Thanh Lan',
        'ptkq@longevity.com.vn'    => 'Phan Trần Khánh Quỳn',
        'baoit@longevity.com.vn'   => 'Bảo',
        'tumod@longevity.com.vn'   => 'Tú',
    ];

    public function run(): void
    {
        // org unit code => list email người quản lý (nhiều người được)
        $map = [
            'company'          => ['mstuyet@longevity.com.vn', 'msan@longevity.com.vn'],
            'branch-hcm'       => ['tnkn@longevity.com.vn'],
            'marketing-dn'     => ['ltkp@longevity.com.vn'],
            'team-giang'       => ['ttg@longevity.com.vn'],
            'team-hoi-hn'      => ['tvh@longevity.com.vn'],
            'team-hoi-sale'    => ['nhd@longevity.com.vn'],
            'team-ashley'      => ['tbt@longevity.com.vn', 'hbtl@longevity.com.vn'],
            'team-ashley-sale' => ['ptkq@longevity.com.vn'],
            'ops-run'          => ['baoit@longevity.com.vn'],
            'ops-monitor-sub'  => ['tumod@longevity.com.vn'],
        ];

        foreach ($map as $code => $emails) {
            $unit = OrgUnit::firstWhere('code', $code);
            if (! $unit) {
                $this->command?->warn("Bỏ qua manager: không thấy org '$code'");
                continue;
            }
            // Match qua email; fallback qua tên nếu email đã rename.
            $names = array_values(array_filter(array_map(fn ($e) => self::EMAIL_TO_NAME[$e] ?? null, $emails)));
            $ids = User::where(function ($q) use ($emails, $names) {
                $q->whereIn('email', $emails);
                if ($names) $q->orWhereIn('name', $names);
            })->pluck('id')->unique()->all();

            if (empty($ids)) {
                $this->command?->warn("Bỏ qua manager cho '$code': không thấy user nào");
                continue;
            }
            $unit->managers()->syncWithoutDetaching($ids);
        }

        $this->command?->info('OrgUnitManagerSeeder: đã gán người quản lý các đơn vị.');
    }
}

<?php

namespace Database\Seeders;

use App\Models\OrgUnit;
use App\Models\User;
use Illuminate\Database\Seeder;

class OrgUnitManagerSeeder extends Seeder
{
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
            $ids = User::whereIn('email', $emails)->pluck('id')->all();
            if (empty($ids)) {
                $this->command?->warn("Bỏ qua manager cho '$code': không thấy user nào trong " . implode(',', $emails));
                continue;
            }
            $unit->managers()->syncWithoutDetaching($ids);
        }

        $this->command?->info('OrgUnitManagerSeeder: đã gán người quản lý các đơn vị.');
    }
}

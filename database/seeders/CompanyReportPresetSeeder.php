<?php

namespace Database\Seeders;

use App\Models\OrgUnit;
use App\Models\ReportTemplate;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Preset báo cáo list cấp Công ty — mode='list' trong report_templates.
 */
class CompanyReportPresetSeeder extends Seeder
{
    public function run(): void
    {
        $company = OrgUnit::where('code', 'company')->firstOrFail();
        $admin = User::where('email', 'admin@longevity.com.vn')->first();

        $presets = [
            [
                'name' => 'GR Daily Report (New Booking)',
                'config' => [
                    'mode' => 'list',
                    'filters' => [
                        'date_field' => 'received_date',
                        'date_range' => 'today',
                    ],
                    'columns' => ['stt', 'received_date', 'facility', 'name', 'birthday', 'address', 'note'],
                ],
            ],
            [
                'name' => 'GR Monthly Detail',
                'config' => [
                    'mode' => 'list',
                    'filters' => [
                        'date_field' => 'received_date',
                        'date_range' => 'this_month',
                    ],
                    'columns' => ['stt', 'received_date', 'code', 'name', 'phone', 'source_group', 'birthday', 'facility', 'owner', 'receiver', 'note', 'classification'],
                ],
            ],
        ];

        foreach ($presets as $def) {
            ReportTemplate::updateOrCreate(
                ['org_unit_id' => $company->id, 'name' => $def['name']],
                ['config' => $def['config'], 'created_by' => $admin?->id]
            );
        }

        $this->command?->info('CompanyReportPresetSeeder: 2 preset list @ Công ty đã đồng bộ.');
    }
}

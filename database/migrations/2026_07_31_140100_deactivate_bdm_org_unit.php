<?php

use App\Models\OrgUnit;
use Illuminate\Database\Migrations\Migration;

/**
 * BDM là nguồn lead (source_group), KHÔNG phải phòng/team.
 * Node OrgUnit "bdm" (id=12) là tồn dư từ mô hình cũ. Deactivate để không hiện
 * trong dropdown chọn team ở kho số phase 2.
 */
return new class extends Migration {
    public function up(): void
    {
        OrgUnit::where('code', 'bdm')->update(['active' => false]);
    }

    public function down(): void
    {
        OrgUnit::where('code', 'bdm')->update(['active' => true]);
    }
};

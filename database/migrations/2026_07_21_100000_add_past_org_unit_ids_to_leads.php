<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            // Danh sách org đã từng giữ lead (past handlers). Cho phép người từng giữ
            // xem read-only + add note sau khi lead chuyển team.
            $table->json('past_org_unit_ids')->nullable()->after('org_unit_id');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('past_org_unit_ids');
        });
    }
};

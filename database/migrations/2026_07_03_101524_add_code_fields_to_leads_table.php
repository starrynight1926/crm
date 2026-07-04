<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            // Mã khách hàng KH-{số}-{loại}[-{nguồn}] — sinh sau khi có id nên nullable
            $table->string('code', 40)->nullable()->unique()->after('id');
            $table->string('type_code', 10)->default('N')->after('code'); // MKT / C / BDM / SI / N
            $table->string('source_code', 10)->nullable()->after('type_code');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['code', 'type_code', 'source_code']);
        });
    }
};

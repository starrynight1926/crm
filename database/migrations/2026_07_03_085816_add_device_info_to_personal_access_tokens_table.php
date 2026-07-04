<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('device_name')->nullable()->after('abilities');
            $table->string('ip', 45)->nullable()->after('device_name');
            $table->string('user_agent', 512)->nullable()->after('ip');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn(['device_name', 'ip', 'user_agent']);
        });
    }
};

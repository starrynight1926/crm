<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_connections', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20); // facebook_ads / tiktok_ads / google_ads / webhook
            $table->string('name');
            $table->text('credentials')->nullable(); // encrypted JSON (Ads API — Phase 7)
            $table->string('webhook_token', 64)->nullable()->unique();
            $table->json('field_mapping')->nullable(); // map field payload → field chuẩn
            $table->string('default_type_code', 10)->default('MKT'); // loại data gán cho lead từ nguồn này
            $table->boolean('active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_connections');
    }
};

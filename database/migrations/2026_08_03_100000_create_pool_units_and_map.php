<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pool_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('pool_units')->nullOnDelete();
            $table->string('name');
            $table->string('code', 80)->unique();
            $table->enum('kind', ['company', 'branch', 'facility', 'department']);
            $table->string('path', 500)->index();
            $table->unsignedSmallInteger('depth')->default(0);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('org_pool_map', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_unit_id')->constrained('org_units')->cascadeOnDelete();
            $table->foreignId('pool_unit_id')->constrained('pool_units')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['org_unit_id', 'pool_unit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('org_pool_map');
        Schema::dropIfExists('pool_units');
    }
};

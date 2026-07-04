<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('org_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('org_units')->nullOnDelete();
            $table->string('name');
            $table->string('code', 50)->unique();
            // Materialized path, VD "/1/4/9/" — query subtree bằng path LIKE '/1/4/%'
            $table->string('path', 500)->index();
            $table->unsignedSmallInteger('depth')->default(0);
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('org_units');
    }
};

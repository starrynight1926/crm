<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facilities', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('parent_id')->nullable()->constrained('facilities')->cascadeOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('staff_members', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('facility_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20); // doctor / consultant
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['facility_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_members');
        Schema::dropIfExists('facilities');
    }
};

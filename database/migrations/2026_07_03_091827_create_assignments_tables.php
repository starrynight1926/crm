<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('org_unit_id')->constrained()->cascadeOnDelete();
            $table->string('data_scope', 20)->default('self'); // self / team / custom
            $table->boolean('active')->default(true);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'active']);
        });

        // Node được tích checkbox khi data_scope = custom (thấy node + toàn bộ con)
        Schema::create('assignment_scope_nodes', function (Blueprint $table) {
            $table->foreignId('assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('org_unit_id')->constrained()->cascadeOnDelete();
            $table->primary(['assignment_id', 'org_unit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_scope_nodes');
        Schema::dropIfExists('assignments');
    }
};

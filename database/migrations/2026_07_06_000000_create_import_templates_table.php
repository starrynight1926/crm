<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Template import lead: quy tắc map cột (tái dùng) + giá trị mặc định.
 * Dùng chung toàn công ty. Nằm ở clean zone (mysql).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // config = [ {target, header, default} ] — target = field lead (name/phone/...) hoặc 'cf_<id>'
            $table->json('config');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_templates');
    }
};

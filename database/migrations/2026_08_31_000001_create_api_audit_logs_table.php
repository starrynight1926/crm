<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('api_audit_logs', function (Blueprint $t) {
            $t->id();
            $t->string('method', 8);
            $t->string('path', 255);
            $t->unsignedSmallInteger('response_status');
            $t->json('request_body')->nullable();
            $t->json('response_body')->nullable();
            $t->string('ip', 45)->nullable();
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->timestamp('created_at')->useCurrent();

            $t->index('path');
            $t->index(['response_status', 'created_at']);
            $t->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_audit_logs');
    }
};

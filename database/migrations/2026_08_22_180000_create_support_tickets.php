<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('name');
            $t->string('co_so')->nullable();
            $t->string('contact')->nullable(); // email/sdt
            $t->text('description');
            $t->string('status', 20)->default('cho_xu_ly'); // cho_xu_ly | da_xu_ly | tu_choi
            $t->timestamps();
            $t->index('status');
            $t->index('user_id');
        });

        Schema::create('support_ticket_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $t->string('sender_type', 10); // user | admin
            $t->string('sender_name');
            $t->foreignId('sender_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->text('body');
            $t->timestamps();
            $t->index('ticket_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_messages');
        Schema::dropIfExists('support_tickets');
    }
};

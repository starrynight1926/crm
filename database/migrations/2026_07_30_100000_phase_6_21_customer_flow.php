<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // --- 1. Thêm cột leads.phase + is_first_visit ---
        Schema::table('leads', function (Blueprint $table) {
            $table->unsignedTinyInteger('phase')->default(1)->after('pipeline_status')
                ->comment('Customer Flow 1..7 — Phase 6.21');
            $table->boolean('is_first_visit')->default(true)->after('phase')
                ->comment('Đến lần đầu; false = khách quay lại, phase reset về 3');
            $table->index('phase');
        });

        // --- 2. Bảng lead_phase_closures: mỗi phase chốt = 1 record ---
        Schema::create('lead_phase_closures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->unsignedTinyInteger('phase');
            $table->foreignId('closed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('closed_at');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['lead_id', 'phase']);
            $table->index(['lead_id', 'phase']);
        });

        // --- 3. Bảng call_logs: mỗi cuộc gọi = 1 record ---
        Schema::create('call_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->comment('thanh_cong | that_bai | khong_nghe_may');
            $table->text('note')->nullable();
            $table->dateTime('called_at');
            $table->timestamps();

            $table->index(['lead_id', 'called_at']);
            $table->index('user_id');
        });

        // --- 4. Bảng booking_logs: mỗi booking = 1 record ---
        Schema::create('booking_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->comment('da_xac_nhan | cho_xac_nhan | huy_doi_lich');
            $table->dateTime('scheduled_at')->nullable();
            $table->foreignId('doctor_id')->nullable()->constrained('staff_members')->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['lead_id', 'scheduled_at']);
            $table->index('user_id');
        });

        // --- 5. Backfill leads.phase cho data cũ ---
        // Rule (design §7):
        //   phase = 4 nếu booking_status IN ('booked','rescheduled')
        //   phase = 2 nếu owner_id IS NULL AND pipeline_status = 'waiting_distribute'
        //   phase = 1 nếu owner_id IS NULL AND receiver_id IS NULL AND classification = 'new'
        //   phase = 3 mặc định (đang chăm sóc)
        DB::table('leads')->update(['phase' => 3, 'is_first_visit' => true]);
        DB::table('leads')->whereIn('booking_status', ['booked', 'rescheduled'])->update(['phase' => 4]);
        DB::table('leads')
            ->whereNull('owner_id')
            ->where('pipeline_status', 'waiting_distribute')
            ->update(['phase' => 2]);
        DB::table('leads')
            ->whereNull('owner_id')
            ->whereNull('receiver_id')
            ->where('classification', 'new')
            ->update(['phase' => 1]);

        // --- 6. Sinh lead_phase_closures giả lập cho lead cũ ---
        // Với mỗi lead phase = N, sinh closure cho 1..N-1 với closed_by = receiver ?? owner ?? admin id 1,
        // closed_at = leads.created_at, note = '[backfill]'.
        $fallbackUserId = DB::table('users')->min('id') ?: 1;
        $leads = DB::table('leads')->select('id', 'phase', 'owner_id', 'receiver_id', 'created_at')->get();
        $now = now();
        $rows = [];
        foreach ($leads as $lead) {
            $by = $lead->receiver_id ?: ($lead->owner_id ?: $fallbackUserId);
            $ts = $lead->created_at ?: $now;
            for ($p = 1; $p < (int) $lead->phase; $p++) {
                $rows[] = [
                    'lead_id'    => $lead->id,
                    'phase'      => $p,
                    'closed_by'  => $by,
                    'closed_at'  => $ts,
                    'note'       => '[backfill]',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('lead_phase_closures')->insert($chunk);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_logs');
        Schema::dropIfExists('call_logs');
        Schema::dropIfExists('lead_phase_closures');
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['phase']);
            $table->dropColumn(['phase', 'is_first_visit']);
        });
    }
};

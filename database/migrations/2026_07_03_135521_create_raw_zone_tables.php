<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tầng hứng (raw zone) — chạy trên PostgreSQL. Test (sqlite) bỏ qua phần
 * index đặc thù Postgres.
 */
return new class extends Migration
{
    protected $connection = 'pgsql';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        $schema->create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->string('file_name');
            $table->unsignedBigInteger('uploaded_by')->nullable(); // user id bên MySQL (logic)
            $table->json('column_mapping')->nullable(); // map cột file → field chuẩn
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('success')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('duplicated')->default(0);
            $table->timestampTz('created_at')->nullable();
        });

        $schema->create('raw_leads', function (Blueprint $table) {
            $table->id();
            $table->string('source_type', 20); // excel / ads_api / webhook / manual
            $table->string('source_ref')->nullable(); // tên connection / batch import
            $table->foreignId('import_batch_id')->nullable()->constrained('import_batches')->nullOnDelete();
            $table->jsonb('payload');
            $table->string('status', 20)->default('pending'); // pending / processed / failed / duplicate
            $table->text('error_reason')->nullable();
            $table->unsignedBigInteger('clean_lead_id')->nullable(); // id lead bên MySQL (logic, không FK)
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('processed_at')->nullable();

            $table->index('status');
            $table->index(['source_type', 'created_at']);
        });

        $schema->create('ingest_logs', function (Blueprint $table) {
            $table->id();
            $table->string('source_type', 20);
            $table->unsignedBigInteger('connection_id')->nullable(); // bên MySQL (logic)
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->json('request')->nullable();
            $table->json('response')->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->index(['source_type', 'created_at']);
        });

        // Index đặc thù Postgres (GIN trên JSONB + expression index theo phone)
        if (DB::connection($this->connection)->getDriverName() === 'pgsql') {
            DB::connection($this->connection)->statement('CREATE INDEX raw_leads_payload_gin ON raw_leads USING GIN (payload)');
            DB::connection($this->connection)->statement("CREATE INDEX raw_leads_payload_phone ON raw_leads ((payload->>'phone'))");
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        $schema->dropIfExists('ingest_logs');
        $schema->dropIfExists('raw_leads');
        $schema->dropIfExists('import_batches');
    }
};

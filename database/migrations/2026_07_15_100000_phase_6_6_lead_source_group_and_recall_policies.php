<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('source_group', 20)->nullable()->after('ad_source')
                ->comment('marketing|data_cold|bdm|referral|ctv|walk_in');
            $table->string('approval_status', 20)->default('none')->after('source_group')
                ->comment('none|pending|approved|rejected');
            $table->unsignedBigInteger('approval_by')->nullable()->after('approval_status');
            $table->timestamp('approved_at')->nullable()->after('approval_by');
            $table->timestamp('overdue_marked_at')->nullable()->after('approved_at');
            $table->timestamp('recall_at')->nullable()->after('overdue_marked_at');
            $table->boolean('is_permanent_assignment')->default(false)->after('recall_at');
            $table->index('source_group');
            $table->index('approval_status');
            $table->index('recall_at');
        });

        Schema::create('recall_policies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('org_unit_id')->unique();
            $table->unsignedInteger('recall_after_days')->nullable();
            $table->unsignedInteger('escalate_after_days')->nullable();
            $table->boolean('allow_permanent_assignment')->default(true);
            $table->unsignedBigInteger('set_by')->nullable();
            $table->timestamps();
        });

        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::table('lead_distribution_logs', function (Blueprint $table) {
            $table->text('reason')->nullable()->after('rule_id');
        });
    }

    public function down(): void
    {
        Schema::table('lead_distribution_logs', function (Blueprint $table) {
            $table->dropColumn('reason');
        });
        Schema::dropIfExists('system_settings');
        Schema::dropIfExists('recall_policies');
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['source_group']);
            $table->dropIndex(['approval_status']);
            $table->dropIndex(['recall_at']);
            $table->dropColumn([
                'source_group', 'approval_status', 'approval_by', 'approved_at',
                'overdue_marked_at', 'recall_at', 'is_permanent_assignment',
            ]);
        });
    }
};

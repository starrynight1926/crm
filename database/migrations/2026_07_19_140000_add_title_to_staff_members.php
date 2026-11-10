<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6.12 — Tách "Tên\n(Chức vụ)" trong staff_members.name thành 2 cột riêng name + title.
 * Backfill: split theo \n( , loại bỏ ) cuối.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('staff_members', function (Blueprint $table) {
            $table->string('title', 255)->nullable()->after('name');
        });

        foreach (DB::table('staff_members')->get() as $s) {
            if (! str_contains($s->name, "\n(")) {
                continue;
            }
            [$name, $rest] = explode("\n(", $s->name, 2);
            $title = rtrim($rest, ')');
            DB::table('staff_members')->where('id', $s->id)->update([
                'name' => trim($name),
                'title' => trim($title),
            ]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('staff_members')->whereNotNull('title')->get() as $s) {
            DB::table('staff_members')->where('id', $s->id)->update([
                'name' => $s->name . "\n(" . $s->title . ')',
            ]);
        }
        Schema::table('staff_members', function (Blueprint $table) {
            $table->dropColumn('title');
        });
    }
};

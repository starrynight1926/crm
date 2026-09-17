<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 6.21g (2026-07-30) — Rename org_units "Team Booking" → "Team Tele"
 * đồng bộ với việc đã rename role Team booking → Team Tele.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('org_units')->where('name', 'Team Booking')->update(['name' => 'Team Tele']);
    }

    public function down(): void
    {
        DB::table('org_units')->where('name', 'Team Tele')->update(['name' => 'Team Booking']);
    }
};

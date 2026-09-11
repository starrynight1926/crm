<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;

/**
 * Thêm perm `lead.source_all` — up data mọi nguồn (bypass SOURCE_PERMISSIONS).
 * Chưa gán cho role nào; admin có thể tick tay khi cần.
 */
return new class extends Migration {
    public function up(): void
    {
        $maxPos = (int) (\DB::table('permissions')->max('position') ?? 0);
        Permission::updateOrCreate(
            ['key' => 'lead.source_all'],
            [
                'label' => 'Up data mọi nguồn — bypass gate SOURCE_PERMISSIONS (dropdown Nhóm nguồn không bị disable)',
                'group' => 'lead',
                'position' => $maxPos + 1,
            ]
        );
    }

    public function down(): void
    {
        $id = Permission::where('key', 'lead.source_all')->value('id');
        if (! $id) return;
        \DB::table('permission_role')->where('permission_id', $id)->delete();
        Permission::where('id', $id)->delete();
    }
};

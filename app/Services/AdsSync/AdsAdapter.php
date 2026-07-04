<?php

namespace App\Services\AdsSync;

use App\Models\SourceConnection;

/**
 * Adapter cho từng nền tảng Ads. Mỗi adapter kéo lead mới kể từ last_synced_at
 * và trả về mảng payload chuẩn (name, phone, camp, ad_source, ...).
 */
interface AdsAdapter
{
    /**
     * @return array<int, array<string, mixed>> danh sách payload lead
     */
    public function fetchNewLeads(SourceConnection $connection): array;
}

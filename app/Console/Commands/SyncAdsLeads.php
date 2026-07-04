<?php

namespace App\Console\Commands;

use App\Services\AdsSync\AdsSyncService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ads:sync')]
#[Description('Kéo lead mới từ các kết nối Ads API đang bật (Facebook Lead Ads...)')]
class SyncAdsLeads extends Command
{
    public function handle(AdsSyncService $service): int
    {
        foreach ($service->syncAll() as $result) {
            $this->line(sprintf(
                '%s: %s',
                $result['connection'],
                $result['error'] ? "LỖI — {$result['error']}" : "kéo về {$result['fetched']} lead"
            ));
        }

        return self::SUCCESS;
    }
}

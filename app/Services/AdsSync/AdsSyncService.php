<?php

namespace App\Services\AdsSync;

use App\Jobs\ProcessRawLead;
use App\Models\IngestLog;
use App\Models\RawLead;
use App\Models\SourceConnection;
use RuntimeException;
use Throwable;

/**
 * Kéo lead từ các kết nối Ads đang bật → đổ vào raw zone → pipeline chuẩn hóa.
 * Mọi lần sync (kể cả lỗi) đều ghi ingest_logs để màn Kết nối soi được.
 */
class AdsSyncService
{
    public function adapterFor(string $type): AdsAdapter
    {
        return match ($type) {
            'facebook_ads' => new FacebookLeadAdsAdapter(),
            // TikTok / Google: khung sẵn, cần credentials thật + endpoint chính thức để hoàn thiện
            'tiktok_ads', 'google_ads' => throw new RuntimeException(
                'Adapter ' . $type . ' chưa triển khai — cần tài khoản Ads thật để tích hợp (xem result.md Phase 7).'
            ),
            default => throw new RuntimeException("Loại kết nối không hỗ trợ sync: {$type}"),
        };
    }

    /** @return array{connection: string, fetched: int, error: ?string}[] */
    public function syncAll(): array
    {
        $results = [];

        $connections = SourceConnection::where('active', true)
            ->whereIn('type', ['facebook_ads', 'tiktok_ads', 'google_ads'])
            ->get();

        foreach ($connections as $connection) {
            $results[] = $this->syncConnection($connection);
        }

        return $results;
    }

    public function syncConnection(SourceConnection $connection): array
    {
        try {
            $payloads = $this->adapterFor($connection->type)->fetchNewLeads($connection);

            foreach ($payloads as $payload) {
                $raw = RawLead::create([
                    'source_type' => RawLead::SOURCE_ADS_API,
                    'source_ref' => $connection->name,
                    'payload' => $payload,
                    'status' => RawLead::STATUS_PENDING,
                    'created_at' => now(),
                ]);
                ProcessRawLead::dispatch($raw->id);
            }

            $connection->update(['last_synced_at' => now()]);

            IngestLog::create([
                'source_type' => RawLead::SOURCE_ADS_API,
                'connection_id' => $connection->id,
                'http_status' => 200,
                'response' => ['fetched' => count($payloads)],
                'created_at' => now(),
            ]);

            return ['connection' => $connection->name, 'fetched' => count($payloads), 'error' => null];
        } catch (Throwable $e) {
            IngestLog::create([
                'source_type' => RawLead::SOURCE_ADS_API,
                'connection_id' => $connection->id,
                'http_status' => 500,
                'response' => ['error' => mb_substr($e->getMessage(), 0, 500)],
                'created_at' => now(),
            ]);

            return ['connection' => $connection->name, 'fetched' => 0, 'error' => $e->getMessage()];
        }
    }
}

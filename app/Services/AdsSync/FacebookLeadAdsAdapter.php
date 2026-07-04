<?php

namespace App\Services\AdsSync;

use App\Models\SourceConnection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Facebook Lead Ads qua Graph API.
 * credentials cần: access_token (Page token có leads_retrieval), form_id.
 * Lead form trả field_data dạng [{name, values[]}] → flatten rồi map qua field_mapping.
 */
class FacebookLeadAdsAdapter implements AdsAdapter
{
    public function fetchNewLeads(SourceConnection $connection): array
    {
        $credentials = $connection->credentials ?? [];
        $token = $credentials['access_token'] ?? null;
        $formId = $credentials['form_id'] ?? null;

        if (! $token || ! $formId) {
            throw new RuntimeException('Thiếu access_token hoặc form_id trong credentials.');
        }

        $params = [
            'access_token' => $token,
            'fields' => 'created_time,field_data',
            'limit' => 100,
        ];
        if ($connection->last_synced_at) {
            $params['filtering'] = json_encode([[
                'field' => 'time_created',
                'operator' => 'GREATER_THAN',
                'value' => $connection->last_synced_at->timestamp,
            ]]);
        }

        $response = Http::timeout(30)->get("https://graph.facebook.com/v21.0/{$formId}/leads", $params);

        if ($response->failed()) {
            throw new RuntimeException('Graph API lỗi ' . $response->status() . ': ' . mb_substr($response->body(), 0, 300));
        }

        $payloads = [];
        foreach ($response->json('data') ?? [] as $fbLead) {
            // Flatten field_data: [{name: 'full_name', values: ['A']}] → ['full_name' => 'A']
            $flat = [];
            foreach ($fbLead['field_data'] ?? [] as $field) {
                $flat[$field['name']] = $field['values'][0] ?? null;
            }

            $payload = [];
            foreach ($connection->field_mapping ?: [] as $target => $sourceKey) {
                $payload[$target] = $flat[$sourceKey] ?? null;
            }
            // Fallback field phổ biến của FB lead form
            $payload['name'] ??= $flat['full_name'] ?? $flat['name'] ?? null;
            $payload['phone'] ??= $flat['phone_number'] ?? $flat['phone'] ?? null;
            $payload['ad_source'] ??= 'Facebook Ads';
            $payload['received_date'] ??= isset($fbLead['created_time'])
                ? \Carbon\Carbon::parse($fbLead['created_time'])->toDateString()
                : null;

            $payloads[] = $payload;
        }

        return $payloads;
    }
}

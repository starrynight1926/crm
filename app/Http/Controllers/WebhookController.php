<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessRawLead;
use App\Models\IngestLog;
use App\Models\RawLead;
use App\Models\SourceConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Nhận lead từ landing page: POST /webhook/lead/{token}.
 * Token thuộc source_connections; mọi call đều ghi ingest_logs để debug.
 */
class WebhookController extends Controller
{
    public function store(Request $request, string $token): JsonResponse
    {
        $connection = SourceConnection::where('webhook_token', $token)
            ->where('type', 'webhook')
            ->where('active', true)
            ->first();

        if (! $connection) {
            IngestLog::create([
                'source_type' => RawLead::SOURCE_WEBHOOK,
                'http_status' => 401,
                'request' => $request->all(),
                'response' => ['error' => 'invalid token'],
                'created_at' => now(),
            ]);

            return response()->json(['error' => 'Invalid webhook token'], 401);
        }

        // Map payload theo field_mapping của connection (nếu có), mặc định nhận field chuẩn
        $input = $request->all();
        $payload = [];
        foreach ($connection->field_mapping ?: [] as $target => $sourceKey) {
            $payload[$target] = data_get($input, $sourceKey);
        }
        foreach (['name', 'phone', 'received_date', 'page', 'camp', 'insight', 'link', 'region', 'note'] as $field) {
            $payload[$field] ??= $input[$field] ?? null;
        }

        $raw = RawLead::create([
            'source_type' => RawLead::SOURCE_WEBHOOK,
            'source_ref' => $connection->name,
            'payload' => $payload,
            'status' => RawLead::STATUS_PENDING,
            'created_at' => now(),
        ]);

        ProcessRawLead::dispatch($raw->id);

        IngestLog::create([
            'source_type' => RawLead::SOURCE_WEBHOOK,
            'connection_id' => $connection->id,
            'http_status' => 202,
            'request' => $input,
            'response' => ['raw_lead_id' => $raw->id],
            'created_at' => now(),
        ]);

        return response()->json(['status' => 'accepted', 'raw_lead_id' => $raw->id], 202);
    }
}

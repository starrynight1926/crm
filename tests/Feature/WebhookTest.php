<?php

namespace Tests\Feature;

use App\Models\IngestLog;
use App\Models\Lead;
use App\Models\RawLead;
use App\Models\SourceConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    private function makeConnection(array $attrs = []): SourceConnection
    {
        return SourceConnection::create(array_merge([
            'type' => 'webhook',
            'name' => 'Landing test',
            'webhook_token' => str_repeat('a', 48),
            'default_type_code' => 'MKT',
            'active' => true,
        ], $attrs));
    }

    public function test_valid_webhook_creates_raw_and_clean_lead(): void
    {
        $connection = $this->makeConnection();

        $response = $this->postJson('/webhook/lead/' . $connection->webhook_token, [
            'name' => 'Khách Landing',
            'phone' => '0909111222',
            'camp' => 'LP_T7',
        ]);

        $response->assertStatus(202);

        $raw = RawLead::first();
        $this->assertSame(RawLead::STATUS_PROCESSED, $raw->status); // queue sync trong test
        $this->assertSame('MKT', $raw->payload['type_code']); // default từ connection

        $lead = Lead::firstWhere('phone', '0909111222');
        $this->assertNotNull($lead);
        $this->assertSame('LP_T7', $lead->camp);
        $this->assertTrue(IngestLog::where('connection_id', $connection->id)->where('http_status', 202)->exists());
    }

    public function test_field_mapping_translates_payload(): void
    {
        $connection = $this->makeConnection([
            'field_mapping' => ['name' => 'full_name', 'phone' => 'tel'],
        ]);

        $this->postJson('/webhook/lead/' . $connection->webhook_token, [
            'full_name' => 'Khách Map',
            'tel' => '0909333444',
        ])->assertStatus(202);

        $this->assertNotNull(Lead::firstWhere('phone', '0909333444'));
    }

    public function test_invalid_token_rejected_and_logged(): void
    {
        $this->makeConnection();

        $this->postJson('/webhook/lead/wrong-token', ['name' => 'X', 'phone' => '0909555666'])
            ->assertStatus(401);

        $this->assertSame(0, RawLead::count());
        $this->assertTrue(IngestLog::where('http_status', 401)->exists());
    }

    public function test_inactive_connection_rejected(): void
    {
        $connection = $this->makeConnection(['active' => false]);

        $this->postJson('/webhook/lead/' . $connection->webhook_token, ['name' => 'X', 'phone' => '0909555666'])
            ->assertStatus(401);
    }
}

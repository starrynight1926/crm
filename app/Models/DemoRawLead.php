<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Data staging demo (Postgres). Standalone, dễ reset.
 */
class DemoRawLead extends Model
{
    protected $connection = 'pgsql';

    protected $table = 'demo_raw_leads';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'ngay'    => 'date',
    ];

    /**
     * Danh sách các nguồn cấu hình (config/demo_sources.php), keyed theo 'key'.
     */
    public static function sources(): array
    {
        $out = [];
        foreach (config('demo_sources', []) as $src) {
            $out[$src['key']] = $src;
        }
        return $out;
    }

    public static function source(string $key): ?array
    {
        return static::sources()[$key] ?? null;
    }
}

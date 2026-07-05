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
}

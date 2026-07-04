<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['source_type', 'connection_id', 'http_status', 'request', 'response', 'created_at'])]
class IngestLog extends Model
{
    protected $connection = 'pgsql';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'request' => 'array',
            'response' => 'array',
            'created_at' => 'datetime',
        ];
    }
}

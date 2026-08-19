<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationLog extends Model
{
    protected $fillable = [
        'type',
        'source',
        'status',
        'received_body',
        'validation_result',
        'sent_body',
        'result_body',
        'http_status',
        'products_read',
        'products_updated',
        'updates_failed',
        'error_message',
        'warning',
        'afas',
        'exception',
    ];

    protected $casts = [
        'received_body' => 'array',
        'validation_result' => 'array',
        'sent_body' => 'array',
        'result_body' => 'array',
        'products_read' => 'integer',
        'products_updated' => 'integer',
        'updates_failed' => 'integer',
    ];
}

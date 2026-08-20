<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProcessedAfasDeliveryNote extends Model
{
    public const string STATUS_PENDING = 'pending';
    public const string STATUS_POSTED_TO_GOEDGEPICKT = 'posted_to_goedgepickt';
    public const string STATUS_MARKED_PROCESSED_IN_AFAS = 'marked_processed_in_afas';
    public const string STATUS_FAILED = 'failed';

    protected $fillable = [
        'afas_delivery_note_number',
        'goedgepickt_order_uuid',
        'status',
        'goedgepickt_response',
        'error_message',
        'posted_to_goedgepickt_at',
        'marked_processed_in_afas_at',
    ];

    protected $casts = [
        'goedgepickt_response'        => 'array',
        'posted_to_goedgepickt_at'    => 'datetime',
        'marked_processed_in_afas_at' => 'datetime',
    ];
}

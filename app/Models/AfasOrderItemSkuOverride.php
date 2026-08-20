<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AfasOrderItemSkuOverride extends Model
{
    protected $fillable = [
        'afas_item_code',
        'sku',
        'ean',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}

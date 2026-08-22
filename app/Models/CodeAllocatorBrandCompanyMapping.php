<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CodeAllocatorBrandCompanyMapping extends Model
{
    protected $fillable = [
        'afas_brand_code',
        'afas_brand_name',
        'ean_company',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}

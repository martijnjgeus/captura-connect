<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AfasShopifyVariantSync extends Model
{
    public const string STATUS_PENDING = 'pending';
    public const string STATUS_WAITING_FOR_EAN_COMPANY = 'waiting_for_ean_company';
    public const string STATUS_ALLOCATED = 'allocated';
    public const string STATUS_UPDATED_IN_AFAS = 'updated_in_afas';
    public const string STATUS_SYNCED_TO_SHOPIFY = 'synced_to_shopify';
    public const string STATUS_FAILED = 'failed';

    protected $fillable = [
        'afas_variant_key',
        'afas_item_code',
        'afas_dimension_1',
        'afas_dimension_2',
        'ean_company',
        'ean_company_resolved_at',
        'allocated_ean',
        'allocated_sku',
        'ean_allocated_at',
        'sku_allocated_at',
        'shopify_product_id',
        'shopify_variant_id',
        'status',
        'error_message',
        'afas_payload',
        'allocator_response',
        'afas_update_response',
        'shopify_response',
        'updated_in_afas_at',
        'synced_to_shopify_at',
    ];

    protected $casts = [
        'ean_company_resolved_at' => 'datetime',
        'ean_allocated_at'        => 'datetime',
        'sku_allocated_at'        => 'datetime',
        'updated_in_afas_at'      => 'datetime',
        'synced_to_shopify_at'    => 'datetime',
        'afas_payload'            => 'array',
        'allocator_response'      => 'array',
        'afas_update_response'    => 'array',
        'shopify_response'        => 'array',
    ];
}

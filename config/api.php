<?php

return [
    'orders' => [
        'incoming_api_key' => env('INCOMING_ORDER_API_KEY'),
    ],

    'afas' => [
        'api_url' => env('AFAS_API_URL'),
        'api_key' => env('AFAS_API_KEY'),

        'stock_sync' => [
            'connector'  => env('AFAS_STOCK_SYNC_CONNECTOR', 'FbStock'),
            'chunk_size' => (int)env('AFAS_STOCK_SYNC_CHUNK_SIZE', 100),

            'excluded_supplier_uuids' => [
                env('VERWIMP_GOEDGEPICKT_SUPPLIER_UUID'),
                env('HOOP_FIETSEN_GOEDGEPICKT_SUPPLIER_UUID'),
            ],

            'warehouse_rules' => [
                [
                    'label'         => 'OB-Brands',
                    'supplier_uuid' => env('AFAS_STOCK_SYNC_OB_BRANDS_SUPPLIER_UUID'),
                    'warehouse'     => env('AFAS_STOCK_SYNC_OB_BRANDS_WAREHOUSE'),
                ],
                [
                    'label'         => 'Dansante',
                    'supplier_uuid' => env('AFAS_STOCK_SYNC_DANSANTE_SUPPLIER_UUID'),
                    'warehouse'     => env('AFAS_STOCK_SYNC_DANSANTE_WAREHOUSE'),
                ],
            ],

            'default_warehouse' => env('AFAS_STOCK_SYNC_DEFAULT_WAREHOUSE'),
        ],

        'delivery_note_sync' => [
            'get_connector' => env('AFAS_DELIVERY_NOTE_GET_CONNECTOR', 'MOT_pakbonregels_alle'),

            'filterfieldids' => env(
                'AFAS_DELIVERY_NOTE_FILTER_FIELD_IDS',
                'Distributie_date_time,Administratie;Administratie;Administratie,Pakbonstatus'
            ),

            'filtervalues' => env(
                'AFAS_DELIVERY_NOTE_FILTER_VALUES',
                '[is leeg],10;11;50,Afgehandeld'
            ),

            'operatortypes' => env(
                'AFAS_DELIVERY_NOTE_FILTER_OPERATOR_TYPES',
                '8,1;1;1,7'
            ),

            'update_connector' => env('AFAS_DELIVERY_NOTE_UPDATE_CONNECTOR'),
            'update_key_field' => env('AFAS_DELIVERY_NOTE_UPDATE_KEY_FIELD'),
            'update_method'    => env('AFAS_DELIVERY_NOTE_UPDATE_METHOD', 'PUT'),

            'processed_field'           => env('AFAS_DELIVERY_NOTE_PROCESSED_FIELD', 'Distributie_date_time'),
            'processed_value'           => env('AFAS_DELIVERY_NOTE_PROCESSED_VALUE', 'now'),
            'processed_datetime_format' => env('AFAS_DELIVERY_NOTE_PROCESSED_DATETIME_FORMAT', 'Y-m-d\TH:i:s'),
        ],

        'product_variant_sync' => [
            'get_connector' => env('AFAS_PRODUCT_VARIANT_SYNC_GET_CONNECTOR'),
            'page_size'     => env('AFAS_PRODUCT_VARIANT_SYNC_PAGE_SIZE', 100),

            'created_at_field'  => 'Aangemaakt_op',
            'cms_id_field'      => 'CMS_ID',
            'barcode_field'     => 'Barcode_opgschoond',
            'item_code_field'   => 'Itemcode',
            'dimension_1_field' => 'Dimensie_1',
            'dimension_2_field' => 'Dimensie_2',
            'brand_code_field'  => 'Item_merk_code',
            'brand_name_field'  => 'Item_merk_omschrijving',

            'update_connector' => env('AFAS_PRODUCT_VARIANT_SYNC_UPDATE_CONNECTOR', 'FbUpdateAdB'),
            'update_method'    => env('AFAS_PRODUCT_VARIANT_SYNC_UPDATE_METHOD', 'PUT'),

            'update_item_type_field' => 'VaIt',
            'update_item_type_value' => '2',

            'update_item_code_field'   => 'ItCd',
            'update_dimension_1_field' => 'StL1',
            'update_dimension_2_field' => 'StL2',

            'update_barcode_type_field' => 'VaBc',
            'update_barcode_type_value' => '3',
            'update_barcode_field'      => 'BaCo',

            'update_cms_id_field' => env(
                'AFAS_PRODUCT_VARIANT_SYNC_CMS_ID_UPDATE_FIELD',
                'U786FD90C040B41329F2BF4A90100D4C6'
            ),
        ],
    ],

    'goedgepickt' => [
        'api_url' => env('GOEDGEPICKT_API_URL', 'https://account.goedgepickt.nl/api/v1'),
        'api_key' => env('GOEDGEPICKT_API_KEY'),

        'products_endpoint'       => env('GOEDGEPICKT_PRODUCTS_ENDPOINT', '/products'),
        'stock_mutation_endpoint' => env(
            'GOEDGEPICKT_STOCK_MUTATION_ENDPOINT',
            '/products/{uuid}/stock-mutation'
        ),
        'orders_endpoint'         => env('GOEDGEPICKT_ORDERS_ENDPOINT', '/orders'),
        'app_url'                 => env('GOEDGEPICKT_APP_URL', 'https://captura-business-bikes.goedgepickt.nl'),

        'orders_webshops' => [
            'dansante' => [
                'administration' => env('AFAS_ORDERS_DANSANTE_ADMINISTRATION', '10'),
                'webshop_uuid'   => env('GOEDGEPICKT_ORDERS_DANSANTE_WEBSHOP_UUID'),
            ],

            'ob_brands' => [
                'administration' => env('AFAS_ORDERS_OB_BRANDS_ADMINISTRATION', '11'),
                'webshop_uuid'   => env('GOEDGEPICKT_ORDERS_OB_BRANDS_WEBSHOP_UUID'),
            ],
        ],
    ],

    'code_allocator' => [
        'url'                  => env('CODE_ALLOCATOR_URL', 'https://code-allocator.captura-group.com/api/codes/request'),
        'staging_url'          => env('CODE_ALLOCATOR_STAGING_URL', 'https://codealloca9523.builtwithrocket.new/api/codes/request'),
        'use_staging'          => env('CODE_ALLOCATOR_USE_STAGING', true),
        'token'                => env('CODE_ALLOCATOR_TOKEN'),
        'default_ean_company'  => env('CODE_ALLOCATOR_DEFAULT_EAN_COMPANY'),
        'rucanor_ean_company'  => env('CODE_ALLOCATOR_RUCANOR_EAN_COMPANY'),
        'papillon_ean_company' => env('CODE_ALLOCATOR_PAPILLON_EAN_COMPANY'),
    ],
];

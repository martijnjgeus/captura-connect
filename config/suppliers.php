<?php

return [
    'verwimp' => [
        'source' => 'ftps_curl',

        'host' => env('VERWIMP_FTP_HOST'),
        'username' => env('VERWIMP_FTP_USERNAME'),
        'password' => env('VERWIMP_FTP_PASSWORD'),
        'port' => (int) env('VERWIMP_FTP_PORT', 21),
        'root' => env('VERWIMP_FTP_ROOT', ''),
        'file' => env('VERWIMP_STOCK_FILE', 'THOM/LIJSTEN/Voorraad.csv'),
        'disable_epsv' => (bool) env('VERWIMP_FTP_DISABLE_EPSV', false),

        'goedgepickt_supplier_uuid' => env('VERWIMP_GOEDGEPICKT_SUPPLIER_UUID'),

        'format' => 'csv',
        'mode' => 'take_over_with_margin',
        'margin' => (int) env('VERWIMP_STOCK_MARGIN', 0),

        'delimiter' => ';',
        'sku_column' => 'Artikelnr.',
        'ean_column' => 'EANcode',
        'stock_column' => 'Aantal',
    ],

    'hoop_fietsen' => [
        'source' => 'http',
        'url' => env('HOOP_FIETSEN_STOCK_URL'),
        'username' => env('HOOP_FIETSEN_STOCK_USERNAME'),
        'password' => env('HOOP_FIETSEN_STOCK_PASSWORD'),

        'goedgepickt_supplier_uuid' => env('HOOP_FIETSEN_GOEDGEPICKT_SUPPLIER_UUID'),

        'format' => 'xml',
        'mode' => 'positive_becomes_two',

        'item_node' => 'Artikel',
        'sku_field' => 'Artikelnummer',
        'ean_field' => 'EanNummer',
        'stock_field' => 'Voorraad',
    ],
];

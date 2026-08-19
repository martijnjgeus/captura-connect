<?php

return [
    'orders' => [
        'incoming_api_key' => env('INCOMING_ORDER_API_KEY'),
    ],

    'afas' => [
        'api_url' => env('AFAS_API_URL'),
        'api_key' => env('AFAS_API_KEY'),
    ],

    'goedgepickt' => [
        'api_url' => env('GOEDGEPICKT_API_URL', 'https://account.goedgepickt.nl/api/v1'),
        'api_key' => env('GOEDGEPICKT_API_KEY'),

        'products_endpoint'       => env('GOEDGEPICKT_PRODUCTS_ENDPOINT', '/products'),
        'stock_mutation_endpoint' => env(
            'GOEDGEPICKT_STOCK_MUTATION_ENDPOINT',
            '/products/{uuid}/stock-mutation'
        ),
        'app_url'                 => env('GOEDGEPICKT_APP_URL', 'https://captura-business-bikes.goedgepickt.nl'),
    ],
];

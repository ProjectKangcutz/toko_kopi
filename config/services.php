<?php

return [
    'midtrans' => [
        'is_active' => env('MIDTRANS_IS_ACTIVE'),
        'merchant_id' => env('MIDTRANS_MERCHANT_ID'),
        'client_key' => env('MIDTRANS_CLIENT_KEY'),
        'server_key' => env('MIDTRANS_SERVER_KEY'),
        'is_production' => (bool) env('MIDTRANS_IS_PRODUCTION', false),
        'is_sanitized' => (bool) env('MIDTRANS_IS_SANITIZED', true),
        'is_3ds' => (bool) env('MIDTRANS_IS_3DS', true),
    ],

    'rajaongkir' => [
        'base_url' => rtrim((string) env('RAJAONGKIR_BASE_URL', 'https://rajaongkir.komerce.id/api/v1'), '/'),
        'api_key' => env('RAJAONGKIR_API_KEY'),
        'origin_id' => env('RAJAONGKIR_ORIGIN_ID'),
        'timeout' => (int) env('RAJAONGKIR_TIMEOUT', 10),
    ],
];

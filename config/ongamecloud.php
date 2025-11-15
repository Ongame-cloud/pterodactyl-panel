<?php

return [
    'websocket' => [
        'enabled' => env('ONGAMECLOUD_WS_ENABLED', true),
        'host' => env('ONGAMECLOUD_WS_HOST', '0.0.0.0'),
        'port' => env('ONGAMECLOUD_WS_PORT', 8090),
        'ssl' => env('ONGAMECLOUD_WS_SSL', false),
        'ssl_cert' => env('ONGAMECLOUD_WS_SSL_CERT', null),
        'ssl_key' => env('ONGAMECLOUD_WS_SSL_KEY', null),
        'auth_token' => env('ONGAMECLOUD_WS_AUTH_TOKEN', null),
        'max_connections' => env('ONGAMECLOUD_WS_MAX_CONNECTIONS', 100),
        'timeout' => env('ONGAMECLOUD_WS_TIMEOUT', 30),
        'log_enabled' => env('ONGAMECLOUD_WS_LOG_ENABLED', true),
        'log_retention_days' => env('ONGAMECLOUD_WS_LOG_RETENTION_DAYS', 7),
    ],
];

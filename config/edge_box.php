<?php

return [
    'api_key' => env('EDGE_BOX_API_KEY'),
    'cloud_api_url' => env('CLOUD_API_URL'),
    'store_id' => env('STORE_ID'),
    'sqlite_lock_ttl' => env('EDGE_SQLITE_LOCK_TTL', 120),
    'payment_reconcile_enabled' => env('EDGE_PAYMENT_RECONCILE_ENABLED', true),
    'payment_reconcile_interval' => env('EDGE_PAYMENT_RECONCILE_INTERVAL', 30),
    'master_sync_enabled' => env('EDGE_MASTER_SYNC_ENABLED', true),
    'master_sync_interval' => env('EDGE_MASTER_SYNC_INTERVAL', 300),
    'sync_interval' => env('SYNC_INTERVAL', '1m'),
    'timezone' => env('APP_TIMEZONE', 'Asia/Manila'),
];

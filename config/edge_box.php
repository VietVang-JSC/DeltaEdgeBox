<?php

return [
    'api_key' => env('EDGE_BOX_API_KEY'),
    'cloud_api_url' => env('CLOUD_API_URL'),
    'store_id' => env('STORE_ID'),
    'sqlite_lock_ttl' => 600,
    'sync_interval' => env('SYNC_INTERVAL', '1m'),
    'timezone' => env('APP_TIMEZONE', 'Asia/Ho_Chi_Minh'),
];

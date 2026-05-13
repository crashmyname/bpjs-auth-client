<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Auth Service URL
    |--------------------------------------------------------------------------
    */
    'base_url' => env('AUTH_SERVICE_URL', 'http://localhost:8000'),
    
    /*
    |--------------------------------------------------------------------------
    | Application Credentials
    |--------------------------------------------------------------------------
    */
    'app_key' => env('AUTH_APP_KEY', ''),
    'app_secret' => env('AUTH_APP_SECRET', ''),
    
    /*
    |--------------------------------------------------------------------------
    | HTTP Client Configuration
    |--------------------------------------------------------------------------
    */
    'timeout' => 30,
    'max_retries' => 3,
    'verify_ssl' => env('APP_ENV') === 'production',
    
    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    */
    'cache' => [
        'driver' => env('AUTH_CACHE_DRIVER', 'memory'), // memory, redis
        'prefix' => 'auth_service_',
        
        'redis' => [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => env('REDIS_PORT', 6379),
            'password' => env('REDIS_PASSWORD', null),
            'database' => env('REDIS_DB', 0),
            'timeout' => 2.5,
        ],
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Logging Configuration
    |--------------------------------------------------------------------------
    */
    'logging' => [
        'enabled' => true,
        'channel' => 'auth-service-client',
        'level' => env('LOG_LEVEL', 'INFO'),
        'path' => storage_path('logs/auth-service-client/'),
    ],
];
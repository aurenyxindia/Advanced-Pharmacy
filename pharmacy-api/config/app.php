<?php

use App\Support\Env;

return [
    'name'     => Env::get('APP_NAME', 'Advanced Pharmacy Suite'),
    'env'      => Env::get('APP_ENV', 'local'),
    'debug'    => Env::bool('APP_DEBUG', false),
    'url'      => Env::get('APP_URL', 'http://localhost:8080'),
    'timezone' => Env::get('APP_TIMEZONE', 'Asia/Kolkata'),

    'cors' => [
        'allowed_origins' => array_filter(array_map(
            'trim',
            explode(',', Env::get('CORS_ALLOWED_ORIGINS', 'http://localhost:4200'))
        )),
        'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With', 'X-Client-Id', 'X-Branch-Id'],
        'max_age'         => 86400,
    ],

    'jwt' => [
        'secret'      => Env::get('JWT_SECRET', ''),
        'issuer'      => Env::get('JWT_ISSUER', 'advanced-pharmacy'),
        'access_ttl'  => (int) Env::get('JWT_ACCESS_TTL', 900),
        'refresh_ttl' => (int) Env::get('JWT_REFRESH_TTL', 1209600),
        'algo'        => 'HS256',
    ],

    'rate_limit' => [
        'max_attempts'  => (int) Env::get('RATE_LIMIT_MAX_ATTEMPTS', 5),
        'decay_seconds' => (int) Env::get('RATE_LIMIT_DECAY_SECONDS', 300),
    ],

    'upload' => [
        'max_mb'        => (int) Env::get('UPLOAD_MAX_MB', 10),
        'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'],
    ],
];

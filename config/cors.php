<?php
return [
    'paths' => ['api/*'], 'allowed_methods' => ['*'],
    'allowed_origins' => array_filter(array_map('trim', explode(',', env('FRONTEND_ORIGINS', 'http://localhost:8080,http://127.0.0.1:8080')))),
    'allowed_origins_patterns' => [], 'allowed_headers' => ['Accept','Content-Type','X-CSRF-TOKEN','X-Requested-With','Idempotency-Key'],
    'exposed_headers' => ['Retry-After'], 'max_age' => 3600, 'supports_credentials' => true,
];

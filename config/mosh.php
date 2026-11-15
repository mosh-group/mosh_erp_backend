<?php
return [
    'spec' => json_decode(file_get_contents(resource_path('api-spec.json')), true),
    'allow_setup' => env('MOSH_ALLOW_SETUP', true),
    'demo_data' => env('MOSH_DEMO_DATA', false),
    'max_file_bytes' => null, // R34: attachment size is constrained by actual storage/runtime only.
    'frontend_origins' => array_filter(array_map('trim', explode(',', env('FRONTEND_ORIGINS', 'http://localhost:8080,http://127.0.0.1:8080')))),
];

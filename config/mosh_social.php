<?php
// Optional environment overrides. Existing .env and APP_KEY are never rewritten.
return [
    'app_id' => env('MOSH_INSTAGRAM_APP_ID', ''),
    'app_secret' => env('MOSH_INSTAGRAM_APP_SECRET', ''),
    'redirect_uri' => env('MOSH_INSTAGRAM_REDIRECT_URI', ''),
    'public_base_url' => env('MOSH_INSTAGRAM_PUBLIC_BASE_URL', ''),
    'graph_version' => env('MOSH_INSTAGRAM_GRAPH_VERSION', 'v26.0'),
];

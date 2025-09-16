<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [
        'http://localhost:8080',
        'http://localhost:3000',
        'http://localhost:8081',
        'http://localhost:8082',
        'https://isuetest.netlify.app',
        'https://isuemapa9dmn.netlify.app',
        'https://web-production-4859.up.railway.app',
        'https://*.netlify.app',
        'https://*.railway.app'
    ],
    // Allow any Netlify and Railway subdomain (optional, safer to list exact domains above)
    'allowed_origins_patterns' => [
        '#^https:\/\/[a-z0-9-]+\.netlify\.app$#i',
        '#^https:\/\/[a-z0-9-]+\.railway\.app$#i'
    ],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];
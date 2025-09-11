<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [
        'http://localhost:8080',
        'http://localhost:3000',
        'http://localhost:8081',
        'https://isueadmin.netlify.app',
        'https://isuetest.netlify.app',
    ],
    // Allow any Netlify subdomain (optional, safer to list exact domains above)
    'allowed_origins_patterns' => ['#^https:\/\/[a-z0-9-]+\.netlify\.app$#i'],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];
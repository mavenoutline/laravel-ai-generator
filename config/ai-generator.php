<?php

return [
    'provider' => env('AI_GENERATOR_PROVIDER', 'ollama'),
    'model' => env('AI_GENERATOR_MODEL', 'codellama:latest'),
    'base_url' => env('AI_GENERATOR_BASE_URL', 'http://localhost:11434/api/generate'),
    'enabled' => env('AI_GENERATOR_ENABLED', true),
    'templates_path' => base_path('vendor/mavenoutline/lumen-ai-generator/templates'),
    'routes_file' => 'routes/api.php',
    'output_paths' => [
        'model' => app_path('Models'),
        'controller' => app_path('Http/Controllers/Api'),
        'service' => app_path('Services'),
        'request' => app_path('Http/Requests'),
        'resource' => app_path('Http/Resources'),
    ],
    'force_overwrite' => env('AI_GENERATOR_FORCE_OVERWRITE', false),
    'debug' => env('AI_GENERATOR_DEBUG', false),
];

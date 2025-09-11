<?php
return [
    'provider' => env('AI_PROVIDER', 'ollama'), // 'ollama' or 'template'
    'model' => env('AI_MODEL', 'codellama:latest'),
    'ollama_api' => env('OLLAMA_API', 'http://localhost:11434'),
    'templates_path' => base_path('vendor/mavenoutline/laravel-ai-generator/templates'),
    'naming' => [
        'model' => function ($table) {
            return ucfirst(Str::singular($table));
        },
    ],
    'use_fallback_templates' => true,
];

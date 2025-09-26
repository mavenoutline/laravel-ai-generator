<?php

namespace MavenOutline\AiGenerator;

use Illuminate\Support\ServiceProvider;
use MavenOutline\AiGenerator\Commands\GenerateApiCommand;
use MavenOutline\AiGenerator\Services\AiClient;

class AiGeneratorServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/ai-generator.php', 'ai-generator');

        $this->app->bind('MavenOutline\\AiGenerator\\Services\\AiClient', function ($app) {
            return new AiClient(config('ai-generator.ollama_api'), config('ai-generator.model'), config('ai-generator.provider'));
        });

        $this->commands([
            GenerateApiCommand::class,
        ]);
    }

    public function boot()
    {
        // Publish config and templates
        if (method_exists($this, 'publishes')) {
            $this->publishes([
                __DIR__ . '/../../config/ai-generator.php' => config_path('ai-generator.php'),
            ], 'config');

            $this->publishes([
                __DIR__ . '/../../templates' => base_path('templates/ai-generator'),
            ], 'templates');
        }
    }
}

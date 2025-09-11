<?php

namespace MavenOutline\AiGenerator;

use Illuminate\Support\ServiceProvider;
use MavenOutline\AiGenerator\Commands\GenerateApiCommand;
use MavenOutline\AiGenerator\Drivers\OllamaDriver;
use MavenOutline\AiGenerator\Drivers\StubDriver;
use MavenOutline\AiGenerator\Contracts\AiDriverContract;

class AiGeneratorServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../../../../config/ai-generator.php', 'ai-generator');

        $this->app->singleton(AiDriverContract::class, function ($app) {
            $provider = config('ai-generator.provider', 'ollama');
            if ($provider === 'ollama') {
                return new OllamaDriver(config('ai-generator.base_url'), config('ai-generator.model'), $app->make('Psr\Log\LoggerInterface'));
            }
            return new StubDriver(config('ai-generator.templates_path'));
        });

        $this->app->singleton('mavenoutline.ai.promptbuilder', function ($app) {
            return new \MavenOutline\AiGenerator\Services\PromptBuilder();
        });

        $this->app->singleton('mavenoutline.ai.schema', function ($app) {
            return new \MavenOutline\AiGenerator\Services\SchemaInspector();
        });

        $this->app->singleton('mavenoutline.ai.codewriter', function ($app) {
            return new \MavenOutline\AiGenerator\Services\CodeWriter();
        });

        $this->commands([
            GenerateApiCommand::class,
        ]);
    }

    public function boot()
    {
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

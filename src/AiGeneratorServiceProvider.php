<?php

namespace MavenOutline\AiGenerator;

use Illuminate\Support\ServiceProvider;
use MavenOutline\AiGenerator\Services\GeneratorService;
use MavenOutline\AiGenerator\Services\PromptBuilder;
use MavenOutline\AiGenerator\Services\SchemaInspector;
use MavenOutline\AiGenerator\Services\CodeWriter;
use MavenOutline\AiGenerator\Drivers\OllamaDriver;
use MavenOutline\AiGenerator\Drivers\StubDriver;
use MavenOutline\AiGenerator\Contracts\AiDriverContract;

class AiGeneratorServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../../../../config/ai-generator.php', 'ai-generator');

        $this->app->singleton(AiDriverContract::class, function ($app) {
            $provider = config('ai-generator.provider', 'stub');
            if ($provider === 'ollama') {
                return new OllamaDriver(config('ai-generator.base_url'), config('ai-generator.model'), $app->make('Psr\\Log\\LoggerInterface'));
            }
            return new StubDriver();
        });

        $this->app->singleton(PromptBuilder::class, function () {
            return new PromptBuilder();
        });
        $this->app->singleton(SchemaInspector::class, function () {
            return new SchemaInspector();
        });
        $this->app->singleton(CodeWriter::class, function () {
            return new CodeWriter();
        });
        $this->app->singleton(GeneratorService::class, function ($app) {
            return new GeneratorService($app->make(AiDriverContract::class), $app->make(PromptBuilder::class), $app->make(SchemaInspector::class), $app->make(CodeWriter::class));
        });

        // register command only in Laravel/Lumen consoles
        if ($this->app->runningInConsole()) {
            $this->commands([
                \MavenOutline\AiGenerator\Commands\GenerateApiCommand::class,
            ]);
        }
    }

    public function boot()
    {
        if (method_exists($this, 'publishes')) {
            $this->publishes([__DIR__ . '/../../config/ai-generator.php' => config_path('ai-generator.php')], 'config');
            $this->publishes([__DIR__ . '/../../templates' => base_path('templates/ai-generator')], 'templates');
        }
    }
}

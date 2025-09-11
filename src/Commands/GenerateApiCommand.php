<?php

namespace MavenOutline\AiGenerator\Commands;

use Illuminate\Console\Command;
use MavenOutline\AiGenerator\Support\ClassNameHelper;
use MavenOutline\AiGenerator\Contracts\AiDriverContract;
use Illuminate\Support\Str;

class GenerateApiCommand extends Command
{
    protected $signature = 'ai:generate {tables*} {--force} {--provider=}';
    protected $description = 'Generate API artifacts for given DB tables using configured AI provider.';

    protected AiDriverContract $driver;

    public function __construct(AiDriverContract $driver)
    {
        parent::__construct();
        $this->driver = $driver;
    }

    public function handle()
    {
        $tables = $this->argument('tables');
        $force = $this->option('force') || config('ai-generator.force_overwrite', false);
        $provider = $this->option('provider') ?? config('ai-generator.provider', 'ollama');
        $promptBuilder = app('mavenoutline.ai.promptbuilder');
        $schemaInspector = app('mavenoutline.ai.schema');
        $writer = app('mavenoutline.ai.codewriter');

        foreach ($tables as $table) {
            $this->info("Processing table: {$table}");

            $columns = $schemaInspector->getColumns($table);
            $dbName = $schemaInspector->resolveDatabaseName();
            $relationships = $schemaInspector->getForeignKeys($dbName, $table);

            $modelName = ClassNameHelper::modelName($table);
            $this->info("Resolved model name: {$modelName}");

            // Build prompts
            $prompts = [
                'model' => $promptBuilder->buildModelPrompt($modelName, $columns, $relationships),
                'request' => $promptBuilder->buildRequestPrompt($modelName, $columns),
                'resource' => $promptBuilder->buildResourcePrompt($modelName, $columns),
                'service' => $promptBuilder->buildServicePrompt($modelName, $columns),
                'controller' => $promptBuilder->buildControllerPrompt($modelName, $columns),
            ];

            $artifactMap = [
                'model' => 'app/Models/' . $modelName . '.php',
                'request' => 'app/Http/Requests/' . $modelName . 'Request.php',
                'resource' => 'app/Http/Resources/' . $modelName . 'Resource.php',
                'service' => 'app/Services/' . $modelName . 'Service.php',
                'controller' => 'app/Http/Controllers/Api/' . $modelName . 'Controller.php',
            ];

            foreach ($prompts as $type => $prompt) {
                $this->info(" -> Generating {$type}...");

                $generated = '';
                if (config('ai-generator.enabled', true) && $provider !== 'stub') {
                    try {
                        $generated = $this->driver->generate($prompt);
                    } catch (\Throwable $e) {
                        $this->error('AI driver failed: ' . $e->getMessage());
                        $generated = '';
                    }
                }

                // If driver returns empty or stub provider, use template fallback
                if (!trim($generated)) {
                    $this->warn("AI response empty for {$type}; using template fallback.");
                    $templatePath = __DIR__ . '/../../templates/' . $type . '.stub';
                    if (file_exists($templatePath)) {
                        $template = file_get_contents($templatePath);
                        $content = str_replace(['{{ClassName}}','{{table}}','{{fillable}}'], [$modelName, $table, $this->fillableList($columns)], $template);
                    } else {
                        $content = $this->minimalTemplate($type, $modelName, $table);
                    }
                } else {
                    // extract php from AI output
                    $content = $this->extractPhp($generated);
                }

                // ensure leading <?php
                if (!Str::startsWith(trim($content), '<?php')) {
                    $content = "<?php\n\n" . $content;
                }

                // write file
                $path = $artifactMap[$type];
                $ok = $writer->write($path, $content, $force);
                if (!$ok) {
                    $this->warn("Skipped writing {$path} (exists). Use --force to overwrite.");
                } else {
                    $this->info("Written: {$path}");
                }
            }

            // append route
            $routeFile = config('ai-generator.routes_file', 'routes/api.php');
            $route = "Route::apiResource('{$table}', '{$modelName}Controller')";
            $this->appendRoute($routeFile, $route);
            $this->info("Route appended: {$routeFile}");
        }

        return 0;
    }

    protected function appendRoute(string $routesFile, string $route)
    {
        $full = base_path($routesFile);
        if (!file_exists($full)) {
            file_put_contents($full, "<?php\n\n");
        }
        $content = file_get_contents($full);
        $line = rtrim($route, ';') . ";\n";
        if (strpos($content, $route) === false) {
            file_put_contents($full, $content . "\n" . $line, LOCK_EX);
        }
    }

    protected function extractPhp(string $raw): string
    {
        // look for ```php blocks
        if (preg_match('/```php\s*(.*?)```/s', $raw, $m)) {
            return trim($m[1]);
        }
        // look for generic ``` ``` blocks
        if (preg_match('/```\s*(.*?)```/s', $raw, $m)) {
            return trim($m[1]);
        }
        // try to find <?php
        if (preg_match('/(<\?php.*)/s', $raw, $m)) {
            return trim($m[1]);
        }
        return trim($raw);
    }

    protected function minimalTemplate(string $type, string $className, string $table): string
    {
        switch ($type) {
            case 'model':
                return "<?php\n\nnamespace App\\Models;\n\nuse Illuminate\\Database\\Eloquent\\Model;\n\nclass {$className} extends Model { protected $table = '{$table}'; }\n";
            case 'controller':
                return "<?php\n\nnamespace App\\Http\\Controllers\\Api;\n\nclass {$className}Controller { }\n";
            default:
                return "<?php\n\n// TODO implement {$type} for {$className}\n";
        }
    }

    protected function fillableList(array $columns): string
    {
        $names = [];
        foreach ($columns as $c) {
            if (!empty($c['Field']) && !in_array($c['Field'], ['id','created_at','updated_at','deleted_at'])) {
                $names[] = "'" . $c['Field'] . "'";
            }
        }
        return implode(', ', $names);
    }
}

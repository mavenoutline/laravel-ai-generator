<?php

namespace MavenOutline\AiGenerator\Services;

use Illuminate\Support\Facades\Log;
use MavenOutline\AiGenerator\Contracts\AiDriverContract;
use MavenOutline\AiGenerator\Support\ClassNameHelper;
use MavenOutline\AiGenerator\Support\TemplateRenderer;
use Illuminate\Support\Str;

class GeneratorService
{
    protected AiDriverContract $driver;
    protected PromptBuilder $prompts;
    protected SchemaInspector $inspector;
    protected CodeWriter $writer;

    public function __construct(AiDriverContract $driver, PromptBuilder $prompts, SchemaInspector $inspector, CodeWriter $writer)
    {
        $this->driver = $driver;
        $this->prompts = $prompts;
        $this->inspector = $inspector;
        $this->writer = $writer;
    }

    /**
     * Generate artifacts for a table. Returns array of written file paths.
     */
    public function generateForTable(string $table, bool $useAi = true, bool $force = false): array
    {
        $columns = $this->inspector->getColumns($table);
        $db = $this->inspector->resolveDatabaseName();
        $relationships = $this->inspector->getForeignKeys($db, $table);
        $uniqueCols = $this->inspector->getUniqueColumns($db, $table);

        $modelName = ClassNameHelper::modelName($table);
        $fillable = $this->prompts->detectFillable($columns);
        $hidden = $this->prompts->detectHidden($columns);
        $casts = $this->prompts->detectCasts($columns);

        $templatesPath = __DIR__ . '/../../templates/';

        $prompts = [
            'model' => $this->prompts->buildModelPrompt($modelName, $columns, $relationships),
            'request' => $this->prompts->buildRequestPrompt($modelName, $columns, $relationships, $uniqueCols),
            'resource' => $this->prompts->buildResourcePrompt($modelName, $columns, $relationships),
            'service' => $this->prompts->buildServicePrompt($modelName, $columns),
            'controller' => $this->prompts->buildControllerPrompt($modelName, $columns, $relationships),
        ];

        $artifactMap = [
            'model' => 'app/Models/' . $modelName . '.php',
            'request' => 'app/Http/Requests/' . $modelName . 'Request.php',
            'resource' => 'app/Http/Resources/' . $modelName . 'Resource.php',
            'service' => 'app/Services/' . $modelName . 'Service.php',
            'controller' => 'app/Http/Controllers/Api/' . $modelName . 'Controller.php',
        ];

        $written = [];

        foreach ($prompts as $type => $prompt) {
            $this->reportStatus("Generating {$type} for table {$table}...");
            $generated = '';
            if ($useAi && config('ai-generator.enabled', true)) {
                try {
                    $generated = $this->driver->generate($prompt);
                } catch (\Throwable $e) {
                    $this->reportStatus("AI generation failed for {$type}, falling back to template.", 'warn');
                    $generated = '';
                }
            }

            if (!trim($generated)) {
                // fallback to template
                $this->reportStatus("Using template for {$type}.");
                $templateFile = $templatesPath . $type . '.stub';
                if (!file_exists($templateFile)) {
                    // fallback minimal
                    $content = $this->minimalTemplate($type, $modelName, $table);
                } else {
                    $template = file_get_contents($templateFile);
                    $replacements = [
                        '{{ClassName}}' => $modelName,
                        '{{table}}' => $table,
                        '{{fillable}}' => TemplateRenderer::stringList($fillable),
                        '{{hidden}}' => TemplateRenderer::stringList($hidden),
                        '{{casts}}' => TemplateRenderer::castsList($casts),
                        '{{relationships_methods}}' => TemplateRenderer::relationshipMethods($relationships),
                        '{{validation_rules}}' => TemplateRenderer::validationRulesList($this->prompts->inferValidationRules($columns, $uniqueCols), $table),
                        '{{fillable_mappings}}' => TemplateRenderer::fillableMappings($fillable),
                        '{{relationship_mappings}}' => TemplateRenderer::relationshipMappings($relationships),
                        '{{filterable_array}}' => TemplateRenderer::filterableArray($fillable),
                        '{{filterable_list}}' => TemplateRenderer::stringList($fillable),
                        '{{route_param}}' => Str::snake(Str::singular($table)),
                        '{{file_columns_array}}' => TemplateRenderer::fileColumnsArrayFromColumns($columns),
                        '{{resource_imports}}' => TemplateRenderer::resourceImports($relationships),
                    ];
                    // do replacements (simple str_replace)
                    $content = str_replace(array_keys($replacements), array_values($replacements), $template);
                }
            } else {
                $this->reportStatus("AI generation completed for {$type}.");
                $content = $this->extractPhp($generated);
            }

            // ensure leading <?php
            if (!Str::startsWith(trim($content), '<?php')) {
                $content = "<?php\n\n" . $content;
            }

            $path = $artifactMap[$type];
            if ($this->writer->write($path, $content, $force)) {
                $this->reportStatus("✅ Written: {$path}");
                $written[] = $path;
            }
        }

        // append route (api resource)
        $routeFile = config('ai-generator.routes_file', 'routes/api.php');
        $routeLine = "Route::apiResource('{$table}', '{$modelName}Controller');";
        $this->appendRoute($routeFile, $routeLine);

        return $written;
    }

    protected function appendRoute(string $routesFile, string $route)
    {
        $full = base_path($routesFile);
        if (!file_exists($full)) {
            file_put_contents($full, "<?php\n\n");
        }
        $content = file_get_contents($full);
        if (strpos($content, $route) === false) {
            file_put_contents($full, $content . "\n" . $route . "\n", LOCK_EX);
        }
    }

    protected function extractPhp(string $raw): string
    {
        if (preg_match('/```php\s*(.*?)```/s', $raw, $m)) return trim($m[1]);
        if (preg_match('/```\s*(.*?)```/s', $raw, $m)) return trim($m[1]);
        if (preg_match('/(<\?php.*)/s', $raw, $m)) return trim($m[1]);
        return trim($raw);
    }

    protected function minimalTemplate($type, $className, $table)
    {
        switch ($type) {
            case 'model':
                return "<?php\n\nnamespace App\\Models;\n\nuse Illuminate\\Database\\Eloquent\\Model;\n\nclass {$className} extends Model { protected $table = '{$table}'; }\n";
            case 'controller':
                return "<?php\n\nnamespace App\\Http\\Controllers\\Api;\n\nclass {$className}Controller { }\n";
        }
        return "<?php\n\n// TODO\n";
    }

    protected function reportStatus(string $message, string $level = 'info'): void
    {
        if (app()->runningInConsole()) {
            $prefix = [
                'info' => 'ℹ️ ',
                'warn' => '⚠️ ',
                'error' => '❌ '
            ][$level] ?? 'ℹ️ ';

            echo "{$prefix} {$message}\n";
        } else {
            // If running via API or job, log instead
            Log::{$level === 'warn' ? 'warning' : ($level === 'error' ? 'error' : 'info')}($message);
        }
    }
}

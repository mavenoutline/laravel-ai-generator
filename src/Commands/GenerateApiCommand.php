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
            $this->info("Processing: {$table}");
            $columns = $schemaInspector->getColumns($table);
            $db = $schemaInspector->resolveDatabaseName();
            $relationships = $schemaInspector->getForeignKeys($db, $table);
            $modelName = ClassNameHelper::modelName($table);

            $fillable = $promptBuilder->detectFillable($columns);
            $hidden = $promptBuilder->detectHidden($columns);
            $casts = $promptBuilder->detectCasts($columns);
            $uniqueIndexes = []; // TODO: detect unique indexes

            $prompts = [
                'model' => $promptBuilder->buildModelPrompt($modelName, $columns, $relationships),
                'request' => $promptBuilder->buildRequestPrompt($modelName, $columns, $relationships, $uniqueIndexes),
                'resource' => $promptBuilder->buildResourcePrompt($modelName, $columns, $relationships),
                'service' => $promptBuilder->buildServicePrompt($modelName, $columns),
                'controller' => $promptBuilder->buildControllerPrompt($modelName, $columns, $relationships),
            ];

            $artifactMap = [
                'model' => 'app/Models/' . $modelName . '.php',
                'request' => 'app/Http/Requests/' . $modelName . 'Request.php',
                'resource' => 'app/Http/Resources/' . $modelName . 'Resource.php',
                'service' => 'app/Services/' . $modelName . 'Service.php',
                'controller' => 'app/Http/Controllers/Api/' . $modelName . 'Controller.php',
            ];

            foreach ($prompts as $type => $prompt) {
                $this->info(" -> {$type}");

                $generated = '';
                if (config('ai-generator.enabled', true) && $provider !== 'stub') {
                    try {
                        $generated = $this->driver->generate($prompt);
                    } catch (\Throwable $e) {
                        $this->error('AI error: ' . $e->getMessage());
                        $generated = '';
                    }
                }

                if (!trim($generated)) {
                    $this->warn('Using template fallback for ' . $type);
                    $template = base_path('vendor/mavenoutline/lumen-ai-generator/templates/' . $type . '.stub');
                    if (!file_exists($template)) {
                        $template = __DIR__ . '/../../templates/' . $type . '.stub';
                    }
                    $content = file_get_contents($template);
                    $repl = [
                        '{{ClassName}}' => $modelName,
                        '{{table}}' => $table,
                        '{{fillable}}' => $this->stringList($fillable),
                        '{{hidden}}' => $this->stringList($hidden),
                        '{{casts}}' => $this->castsList($casts),
                        '{{relationships_methods}}' => $this->relationshipMethods($relationships),
                        '{{validation_rules}}' => $this->validationRulesList($promptBuilder->inferValidationRules($columns, $uniqueIndexes), $table),
                        '{{fillable_mappings}}' => $this->fillableMappings($fillable),
                        '{{relationship_mappings}}' => $this->relationshipMappings($relationships),
                        '{{filterable_list}}' => $this->filterableList($fillable),
                    ];
                    $content = str_replace(array_keys($repl), array_values($repl), $content);
                } else {
                    $content = $this->extractPhp($generated);
                }

                if (!Str::startsWith(trim($content), '<?php')) $content = "<?php\n\n" . $content;
                $path = $artifactMap[$type];
                $ok = $writer->write($path, $content, $force);
                if ($ok) $this->info('Written: ' . $path);
                else $this->warn('Skipped (exists): ' . $path);
            }

            // add route
            $routesFile = config('ai-generator.routes_file', 'routes/api.php');
            $routeLine = "Route::apiResource('{$table}','{$modelName}Controller')";
            $this->appendRoute($routesFile, $routeLine);
            $this->info('Route added to ' . $routesFile);
        }
        return 0;
    }


    // --------------------- Helper methods ---------------------


    protected function fileColumnsArray(array $fileCols): string
    {
        if (empty($fileCols)) return '[]';
        return '[' . implode(', ', array_map(function ($f) {
            return "'" . addslashes($f) . "'";
        }, $fileCols)) . ']';
    }

    protected function resourceImports(array $relationships): string
    {
        if (empty($relationships)) return '';
        $imports = [];
        foreach ($relationships as $r) {
            $ref = $r['REFERENCED_TABLE_NAME'] ?? $r['REFERENCED_TABLE'] ?? $r['referenced_table_name'] ?? null;
            if (!$ref) continue;
            $relatedResource = '\\App\\Http\\Resources\\' . Str::studly(Str::singular($ref)) . 'Resource';
            $imports[] = 'use ' . $relatedResource . ';';
        }
        return implode("\n", array_values(array_unique($imports)));
    }

    protected function appendRoute(string $routesFile, string $route)
    {
        $full = base_path($routesFile);
        if (!file_exists($full)) file_put_contents($full, "<?php\n\n");
        $content = file_get_contents($full);
        if (strpos($content, $route) === false) file_put_contents($full, $content . "\n" . rtrim($route, ';') . ";\n", LOCK_EX);
    }

    protected function extractPhp(string $raw): string
    {
        if (preg_match('/```php\\s*(.*?)```/s', $raw, $m)) return trim($m[1]);
        if (preg_match('/```\\s*(.*?)```/s', $raw, $m)) return trim($m[1]);
        if (preg_match('/(<\\?php.*)/s', $raw, $m)) return trim($m[1]);
        return trim($raw);
    }

    protected function stringList(array $items): string
    {
        if (empty($items)) return '';
        return implode(",\n        ", array_map(function ($v) {
            return "'" . addslashes($v) . "'";
        }, $items));
    }

    protected function castsList(array $casts): string
    {
        if (empty($casts)) return '';
        $parts = [];
        foreach ($casts as $k => $v) $parts[] = "'" . addslashes($k) . "' => '" . addslashes($v) . "'";
        return implode(",\n        ", $parts);
    }

    protected function relationshipMethods(array $relationships): string
    {
        if (empty($relationships)) return '';
        $out = [];
        foreach ($relationships as $r) {
            $col = $r['COLUMN_NAME'] ?? $r['Column_name'] ?? null;
            $ref = $r['REFERENCED_TABLE_NAME'] ?? $r['REFERENCED_TABLE'] ?? null;
            $refCol = $r['REFERENCED_COLUMN_NAME'] ?? $r['REFERENCED_COLUMN'] ?? 'id';
            if (!$col || !$ref) continue;
            $method = Str::camel(Str::before($col, '_'));
            $relatedModel = Str::studly(Str::singular($ref));
            $out[] = "    public function {$method}()\n    {\n        return \$this->belongsTo(\\App\\Models\\{$relatedModel}::class, '{$col}', '{$refCol}');\n    }\n";
        }
        return implode("\n", $out);
    }

    protected function validationRulesList(array $rules, string $table): string
    {
        if (empty($rules)) return '';
        $parts = [];
        foreach ($rules as $f => $r) {
            $r = str_replace('{{table}}', $table, $r);
            $parts[] = "'{$f}' => '{$r}'";
        }
        return implode(",\n            ", $parts);
    }

    protected function fillableMappings(array $fillable): string
    {
        if (empty($fillable)) return '';
        $parts = [];
        foreach ($fillable as $f) $parts[] = "'{$f}' => \$this->{$f},";
        return implode("\n            ", $parts);
    }

    protected function relationshipMappings(array $relationships): string
    {
        if (empty($relationships)) return '';
        $parts = [];
        foreach ($relationships as $r) {
            $col = $r['COLUMN_NAME'] ?? $r['Column_name'] ?? null;
            $ref = $r['REFERENCED_TABLE_NAME'] ?? $r['REFERENCED_TABLE'] ?? null;
            if (!$col || !$ref) continue;
            $method = Str::camel(Str::before($col, '_'));
            $relatedResource = Str::studly(Str::singular($ref)) . 'Resource';
            $parts[] = "'{$method}' => {$relatedResource}::whenLoaded('{$method}'),";
        }
        return implode("\n            ", $parts);
    }

    protected function filterableList(array $fillable): string
    {
        if (empty($fillable)) return "''";
        return implode(', ', array_map(function ($f) {
            return "'" . addslashes($f) . "'";
        }, $fillable));
    }
}

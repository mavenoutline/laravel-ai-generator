<?php

namespace MavenOutline\AiGenerator\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use MavenOutline\AiGenerator\Services\AiClient;
use Exception;

class GenerateApiCommand extends Command
{
    protected $signature = 'ai:generate
        {table : Database table name}
        {--model= : AI model (overrides config)}
        {--provider= : AI provider (overrides config)}
        {--force : Overwrite existing files}
        {--structure-file= : JSON file describing generation structure (overrides defaults)}';

    protected $description = 'Generate Lumen API artifacts from DB table using local AI or template fallback';

    protected AiClient $ai;

    public function __construct(AiClient $ai)
    {
        parent::__construct();
        $this->ai = $ai;
    }

    public function handle()
    {
        $table = $this->argument('table');
        $provider = $this->option('provider') ?? config('ai-generator.provider', 'ollama');
        $modelOpt = $this->option('model') ?? config('ai-generator.model', 'codellama:latest');
        $force = (bool)$this->option('force');
        $structureFile = $this->option('structure-file');

        $this->info("Generating API for table '{$table}' (provider: {$provider}, model: {$modelOpt})");

        // 1) Load schema
        try {
            // get columns (MySQL flavor). Works for many installs; if needed adapt for other DBs.
            $columns = DB::select("SHOW FULL COLUMNS FROM `{$table}`");
        } catch (Exception $e) {
            $this->error("Failed to read schema for '{$table}': " . $e->getMessage());
            return 1;
        }

        // database name for foreign key queries
        $database = $this->resolveDatabaseName();

        // 2) Foreign keys (optional)
        $relationships = $this->detectForeignKeys($database, $table);

        // 3) Build metadata helpers
        $metadata = $this->buildMetadata($table, $columns, $relationships);

        // 4) Decide artifacts to generate (default set)
        $artifacts = $this->resolveArtifacts($structureFile);

        // 5) For each artifact: build prompt, ask AI, sanitize result, fallback, write file
        foreach ($artifacts as $artifact) {
            $type = $artifact['type'] ?? $artifact; // allow flat strings or objects
            $this->info("Generating artifact: {$type}");

            $className = $this->classNameForType($type, $metadata['className']);
            $prompt = $this->buildPromptForType($type, $metadata, $className, $artifact);

            $raw = '';
            try {
                // set model dynamically if ai client supports configuration
                $raw = $this->ai->generate($prompt);
            } catch (Exception $e) {
                $this->error("AI call failed for {$type}: " . $e->getMessage());
            }

            // 6) Extract PHP code from AI response (if any)
            $code = $this->extractPhpFromResponse($raw);

            // 7) If empty or invalid -> fallback template
            if (!trim($code)) {
                $this->warn("AI returned empty for {$type} — using template fallback.");
                $code = $this->renderTemplate($type, $className, $table, $columns, $relationships);
            } else {
                // ensure we have a php opening tag
                $code = $this->ensurePhpTag($code);
                // try to ensure namespace & class are present, otherwise wrap with minimal file
                $code = $this->normalizeGeneratedCode($type, $className, $code);
            }

            // 8) Write file to app path
            $path = $this->targetPathForType($type, $className);
            $fullPath = $this->appBasePath($path);

            if (file_exists($fullPath) && !$force) {
                $this->warn("File exists: {$path} (use --force to overwrite). Skipping.");
                continue;
            }

            $this->makeDirIfNeeded(dirname($fullPath));
            file_put_contents($fullPath, $code);
            $this->info("Created: {$path}");
        }

        // 9) Update routes (append apiResource)
        $routesFile = config('ai-generator.routes_file', 'routes/web.php');
        if (!empty($artifacts)) {
            $classBase = $metadata['className'];
            $routeLine = "Route::apiResource('" . $table . "', '" . $classBase . "Controller')";
            $this->appendToRoutes($routesFile, $routeLine);
            $this->info("Route registered in {$routesFile}: {$routeLine}");
        }

        $this->info('✅ Generation complete.');
        return 0;
    }

    protected function resolveDatabaseName(): string
    {
        // Try config/database then env fallback
        $defaultConn = config('database.default');
        $dbName = null;
        if ($defaultConn && config("database.connections.{$defaultConn}.database")) {
            $dbName = config("database.connections.{$defaultConn}.database");
        }
        if (!$dbName) {
            $dbName = env('DB_DATABASE', null);
        }
        if (!$dbName) {
            // attempt to ask PDO
            try {
                $pdo = DB::getPdo();
                $dbName = $pdo->query('select database()')->fetchColumn();
            } catch (Exception $e) {
                $dbName = '';
            }
        }
        return (string)$dbName;
    }

    protected function detectForeignKeys($database, $table)
    {
        if (empty($database)) {
            return [];
        }
        try {
            $sql = "
                SELECT CONSTRAINT_NAME, TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL
            ";
            return DB::select($sql, [$database, $table]);
        } catch (Exception $e) {
            return [];
        }
    }

    protected function buildMetadata($table, $columns, $relationships): array
    {
        $className = Str::studly(Str::singular($table));
        $fillable = [];
        $casts = [];
        $hidden = [];
        foreach ($columns as $col) {
            $name = $col->Field;
            $type = strtolower($col->Type);
            if (in_array($name, ['id', 'created_at', 'updated_at', 'deleted_at'])) {
                continue;
            }
            $fillable[] = $name;

            if (Str::contains($type, ['int', 'tinyint', 'bigint'])) {
                $casts[$name] = 'integer';
            } elseif (Str::contains($type, ['decimal', 'numeric', 'float', 'double'])) {
                $casts[$name] = 'float';
            } elseif (Str::contains($type, ['json'])) {
                $casts[$name] = 'array';
            } elseif (Str::contains($type, ['datetime', 'timestamp', 'date'])) {
                $casts[$name] = 'datetime';
            } elseif (Str::contains($type, ['boolean'])) {
                $casts[$name] = 'boolean';
            }
            if (Str::contains($name, 'password') || Str::contains($name, 'secret') || Str::contains($name, 'token')) {
                $hidden[] = $name;
            }
        }

        return [
            'table' => $table,
            'className' => $className,
            'fillable' => $fillable,
            'casts' => $casts,
            'hidden' => $hidden,
            'columns' => $columns,
            'relationships' => $relationships,
        ];
    }

    protected function resolveArtifacts($structureFile = null): array
    {
        if ($structureFile && file_exists($structureFile)) {
            $raw = file_get_contents($structureFile);
            $data = json_decode($raw, true);
            if (is_array($data) && isset($data['artifacts'])) {
                return $data['artifacts'];
            }
        }
        // default artifacts (order matters)
        return [
            'model',
            'request',
            'resource',
            'service',
            'controller',
        ];
    }

    protected function classNameForType($type, $baseClass)
    {
        switch ($type) {
            case 'request':
                return $baseClass . 'Request';
            case 'resource':
                return $baseClass . 'Resource';
            case 'service':
                return $baseClass . 'Service';
            case 'controller':
                return $baseClass . 'Controller';
            default:
                return $baseClass;
        }
    }

    protected function buildPromptForType($type, $metadata, $className, $artifact = [])
    {
        // common context for all prompts
        $schema = $this->schemaSummary($metadata['columns']);
        $relationships = $this->relationshipsSummary($metadata['relationships']);
        $fillable = $metadata['fillable'];
        $hidden = $metadata['hidden'];
        $casts = $metadata['casts'];

        $common = "Table: {$metadata['table']}\nClass: {$className}\nSchema: {$schema}\nRelationships: {$relationships}\nFillable: " . json_encode($fillable) . "\nHidden: " . json_encode($hidden) . "\nCasts: " . json_encode($casts) . "\n";
        $instructions = "Return only the PHP file content enclosed in a php code block (or plain `<?php ...`) with correct namespace and imports. Use PSR-12. Do not include any commentary outside code fences.";

        switch ($type) {
            case 'model':
                return "Generate a Lumen Eloquent Model class named {$className}.\n{$common}\nDetails: include protected \$table, \$fillable, \$hidden, \$casts, relationship methods for detected foreign keys, and docblocks. {$instructions}\nNamespace: App\\Models";
            case 'request':
                return "Generate a Lumen FormRequest class named {$className}Request.\n{$common}\nDetails: provide rules() with validations inferred from schema (required, max, email, unique where appropriate) and authorize() returning true. {$instructions}\nNamespace: App\\Http\\Requests";
            case 'resource':
                return "Generate a Lumen API Resource class named {$className}Resource.\n{$common}\nDetails: map attributes explicitly (avoid exposing hidden fields). Provide toArray(\$request). {$instructions}\nNamespace: App\\Http\\Resources";
            case 'service':
                return "Generate a Lumen Service class named {$className}Service.\n{$common}\nDetails: implement index(paginate), show(id), create(data), update(id, data), delete(id). Use transactions where appropriate and return models / collections. {$instructions}\nNamespace: App\\Services";
            case 'controller':
                return "Generate a Lumen Controller named {$className}Controller with methods index, store, show, update, destroy.\n{$common}\nDetails: use the {$className}Request for store/update validation, inject {$className}Service in constructor, return {$className}Resource (single) or resource collection, handle not-found and validation exceptions gracefully. {$instructions}\nNamespace: App\\Http\\Controllers";
            default:
                // allow custom prompt override in structure file
                if (is_array($artifact) && isset($artifact['prompt'])) {
                    return $artifact['prompt'];
                }
                return "Generate a PHP class for type {$type} named {$className}. {$common} {$instructions}";
        }
    }

    protected function extractPhpFromResponse($raw)
    {
        if (empty($raw)) {
            return '';
        }

        // 1) Look for ```php code fences
        if (preg_match('/```php\\s*(.*?)```/s', $raw, $m)) {
            return trim($m[1]);
        }

        // 2) Look for generic code fence ```
        if (preg_match('/```\\s*(.*?)```/s', $raw, $m)) {
            return trim($m[1]);
        }

        // 3) Extract from first <?php .. end
        if (preg_match('/(<\\?php.*)/s', $raw, $m)) {
            return trim($m[1]);
        }

        // 4) fallback: return raw
        return trim($raw);
    }

    protected function ensurePhpTag($code)
    {
        $code = ltrim($code);
        if (!Str::startsWith($code, '<?php')) {
            return "<?php\n\n" . $code;
        }
        return $code;
    }

    protected function normalizeGeneratedCode($type, $className, $code)
    {
        // a simple check: if class with expected name not found — wrap it in a minimal file to avoid runtime errors
        if (!preg_match('/class\\s+' . preg_quote($className) . '\\b/', $code)) {
            // Create a very small wrapper if e.g. resource returns just an array or partial snippet
            switch ($type) {
                case 'model':
                    return $this->renderTemplate('model', $className, null, null, null);
                case 'controller':
                    return $this->renderTemplate('controller', $className, null, null, null);
                case 'request':
                    return $this->renderTemplate('request', $className, null, null, null);
                case 'resource':
                    return $this->renderTemplate('resource', $className, null, null, null);
                case 'service':
                    return $this->renderTemplate('service', $className, null, null, null);
                default:
                    return $code;
            }
        }

        // otherwise return as-is
        return $code;
    }

    protected function renderTemplate($type, $className, $table = null, $columns = null, $relationships = null)
    {
        // minimal safe templates (expand as needed)
        if ($type === 'model') {
            $fillable = "[]";
            if (is_array($columns)) {
                $fields = [];
                foreach ($columns as $col) {
                    if (!in_array($col->Field, ['id', 'created_at', 'updated_at', 'deleted_at'])) {
                        $fields[] = $col->Field;
                    }
                }
                $fillable = "['" . implode("','", $fields) . "']";
            }
            $t = "<?php\n\nnamespace App\\Models;\n\nuse Illuminate\\Database\\Eloquent\\Model;\n\nclass {$className} extends Model\n{\n    protected \$table = '" . ($table ?? Str::snake(Str::plural($className))) . "';\n    protected \$fillable = {$fillable};\n}\n";
            return $t;
        }

        if ($type === 'request') {
            $t = "<?php\n\nnamespace App\\Http\\Requests;\n\nuse Illuminate\\Foundation\\Http\\FormRequest;\n\nclass {$className}Request extends FormRequest\n{\n    public function authorize() { return true; }\n    public function rules() { return [/* TODO: add validation rules */]; }\n}\n";
            return $t;
        }

        if ($type === 'resource') {
            $t = "<?php\n\nnamespace App\\Http\\Resources;\n\nuse Illuminate\\Http\\Resources\\Json\\JsonResource;\n\nclass {$className}Resource extends JsonResource\n{\n    public function toArray(\$request)\n    {\n        return parent::toArray(\$request);\n    }\n}\n";
            return $t;
        }

        if ($type === 'service') {
            $t = "<?php\n\nnamespace App\\Services;\n\nuse App\\Models\\{$className};\n\nclass {$className}Service\n{\n    public function paginate() { return {$className}::paginate(); }\n    public function find(\$id) { return {$className}::findOrFail(\$id); }\n    public function create(\$data) { return {$className}::create(\$data); }\n    public function update(\$id, \$data) { \$m = {$className}::findOrFail(\$id); \$m->update(\$data); return \$m; }\n    public function delete(\$id) { return (bool) {$className}::destroy(\$id); }\n}\n";
            return $t;
        }

        if ($type === 'controller') {
            $t = "<?php\n\nnamespace App\\Http\\Controllers;\n\nuse Laravel\\Lumen\\Routing\\Controller as BaseController;\nuse App\\Services\\{$className}Service;\nuse App\\Http\\Requests\\{$className}Request;\nuse App\\Http\\Resources\\{$className}Resource;\n\nclass {$className}Controller extends BaseController\n{\n    protected \$service;\n\n    public function __construct({$className}Service \$service)\n    {\n        \$this->service = \$service;\n    }\n\n    public function index()\n    {\n        return {$className}Resource::collection(\$this->service->paginate());\n    }\n\n    public function store({$className}Request \$request)\n    {\n        return new {$className}Resource(\$this->service->create(\$request->all()));\n    }\n\n    public function show(\$id)\n    {\n        return new {$className}Resource(\$this->service->find(\$id));\n    }\n\n    public function update({$className}Request \$request, \$id)\n    {\n        return new {$className}Resource(\$this->service->update(\$id, \$request->all()));\n    }\n\n    public function destroy(\$id)\n    {\n        return response()->json(['deleted' => \$this->service->delete(\$id)]);\n    }\n}\n";
            return $t;
        }

        return "<?php\n\n// TODO: implement template for {$type} {$className}\n";
    }

    protected function schemaSummary($columns)
    {
        if (!is_array($columns)) return '';
        $parts = [];
        foreach ($columns as $c) {
            $parts[] = "{$c->Field} {$c->Type} " . ($c->Null === 'NO' ? 'NOT NULL' : 'NULL');
        }
        return implode(', ', $parts);
    }

    protected function relationshipsSummary($relationships)
    {
        if (empty($relationships)) return 'none';
        $parts = [];
        foreach ($relationships as $r) {
            $parts[] = "{$r->COLUMN_NAME} -> {$r->REFERENCED_TABLE_NAME}({$r->REFERENCED_COLUMN_NAME})";
        }
        return implode('; ', $parts);
    }

    protected function targetPathForType($type, $className)
    {
        $map = [
            'model' => "app/Models/{$className}.php",
            'request' => "app/Http/Requests/{$className}Request.php",
            'resource' => "app/Http/Resources/{$className}Resource.php",
            'service' => "app/Services/{$className}Service.php",
            'controller' => "app/Http/Controllers/{$className}Controller.php",
        ];
        return $map[$type] ?? "app/{$type}/{$className}.php";
    }

    protected function appBasePath($relative)
    {
        // Lumen-friendly base path
        if (function_exists('base_path')) {
            return rtrim(base_path($relative), DIRECTORY_SEPARATOR);
        }
        if (app() && method_exists(app(), 'basePath')) {
            return implode(DIRECTORY_SEPARATOR, [app()->basePath(), $relative]);
        }
        // fallback to cwd
        return getcwd() . DIRECTORY_SEPARATOR . $relative;
    }

    protected function makeDirIfNeeded($dir)
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
    }

    protected function appendToRoutes($routesFile, $routeLine)
    {
        $full = $this->appBasePath($routesFile);
        if (!file_exists($full)) {
            file_put_contents($full, "<?php\n\n");
        }
        $content = file_get_contents($full);
        $lineWithSemicolon = rtrim($routeLine, ';') . ";\n";
        if (strpos($content, $routeLine) === false) {
            file_put_contents($full, $content . "\n" . $lineWithSemicolon, LOCK_EX);
        }
    }
}

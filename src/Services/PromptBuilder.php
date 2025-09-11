<?php

namespace MavenOutline\AiGenerator\Services;

use Illuminate\Support\Str;

class PromptBuilder
{
    public function buildModelPrompt(string $modelName, array $columns, array $relationships): string
    {
        $fillable = $this->detectFillable($columns);
        $hidden = $this->detectHidden($columns);
        $casts = $this->detectCasts($columns);
        $relations = $this->inferRelations($columns);

        $prompt = <<<PROMPT
Generate a Laravel 11 Eloquent Model class for "{$modelName}".
- Namespace: App\Models
- Use Illuminate\Database\Eloquent\Model
- Protected \$table = "{$this->inferTableName($modelName)}"
- Fillable fields: {$this->toList($fillable)}
- Hidden fields: {$this->toList($hidden)}
- Casts: {$this->toAssocList($casts)}
- Generate relationships based on these relations: {$this->relationsSummary($relations)}
Use Laravel 11 code style and best practices.
PROMPT;

        return $prompt;
    }

    /**
     * Build AI Prompt for FormRequest (Validation)
     */
    public function buildRequestPrompt(string $modelName, array $columns, array $relationships, array $uniqueCols): string
    {
        $rules = $this->inferValidationRules($columns, $uniqueCols);

        $prompt = <<<PROMPT
Generate a Laravel 11 FormRequest class for validating {$modelName} data.
- Namespace: App\Http\Requests
- Class name: {$modelName}Request
- Use Illuminate\Foundation\Http\FormRequest and Illuminate\Validation\Rule
- Rules (array syntax): {$this->toAssocList($rules)}
- On update, use Rule::unique()->ignore(\$this->route(...)) for unique fields.
PROMPT;

        return $prompt;
    }

    /**
     * Build AI Prompt for Resource (API Transformer)
     */
    public function buildResourcePrompt(string $modelName, array $columns, array $relationships): string
    {
        $fillable = $this->detectFillable($columns);
        $relations = $this->inferRelations($columns);

        $prompt = <<<PROMPT
Generate a Laravel 11 JsonResource class for {$modelName}.
- Namespace: App\Http\Resources
- Class name: {$modelName}Resource
- Should return an array with: {$this->toList($fillable)}
- For relationships: include whenLoaded() calls for these: {$this->relationsSummary($relations)}
Use best practices for API Resource classes.
PROMPT;

        return $prompt;
    }

    /**
     * Build AI Prompt for Service Layer
     */
    public function buildServicePrompt(string $modelName, array $columns): string
    {
        $fillable = $this->detectFillable($columns);

        $prompt = <<<PROMPT
Generate a Laravel 11 Service class for {$modelName}.
- Namespace: App\Services
- Class name: {$modelName}Service
- Must have methods: paginate(array \$filters), store(array \$data), update(\$id, array \$data), delete(\$id)
- Pagination should support filter and sorting on these fields: {$this->toList($fillable)}
Use dependency injection and Eloquent query builder properly.
PROMPT;

        return $prompt;
    }

    /**
     * Build AI Prompt for Controller
     */
    public function buildControllerPrompt(string $modelName, array $columns, array $relationships): string
    {
        $fillable = $this->detectFillable($columns);

        $prompt = <<<PROMPT
Generate a Laravel 11 API Controller for {$modelName}.
- Namespace: App\Http\Controllers\Api
- Class name: {$modelName}Controller
- Use {$modelName}Service for business logic (index, store, show, update, destroy)
- index() should accept filters and pass to service
- store() and update() should use {$modelName}Request
- return JSON responses with resources
PROMPT;

        return $prompt;
    }

    // --- Helper methods to format prompt data ---
    protected function toList(array $items): string
    {
        return empty($items) ? '[]' : implode(', ', $items);
    }

    protected function toAssocList(array $assoc): string
    {
        return empty($assoc) ? '{}' : json_encode($assoc, JSON_PRETTY_PRINT);
    }

    protected function relationsSummary(array $relations): string
    {
        if (empty($relations)) return 'No relations';
        return collect($relations)->map(fn($r) => "{$r['name']} ({$r['type']} -> {$r['model']})")->implode(', ');
    }

    protected function inferTableName(string $modelName): string
    {
        return Str::snake(Str::pluralStudly($modelName));
    }
    /**
     * Detect Fillable fields for Model
     */
    public function detectFillable(array $columns): array
    {
        $exclude = ['id', 'created_at', 'updated_at', 'deleted_at'];
        return collect($columns)
            ->map(fn($c) => $c['Field'] ?? $c['field'] ?? null)
            ->filter(fn($name) => $name && !in_array($name, $exclude, true))
            ->values()
            ->unique()
            ->toArray();
    }

    /**
     * Detect Hidden fields for Model
     */
    public function detectHidden(array $columns): array
    {
        $sensitive = ['password', 'password_hash', 'remember_token', 'api_token', 'token', 'secret', 'access_token'];

        return collect($columns)
            ->map(fn($c) => strtolower($c['Field'] ?? $c['field'] ?? ''))
            ->filter(fn($name) => collect($sensitive)->contains(fn($s) => Str::contains($name, $s)))
            ->unique()
            ->values()
            ->toArray();
    }

    /**
     * Detect Casts for Model
     */
    public function detectCasts(array $columns): array
    {
        return collect($columns)
            ->mapWithKeys(function ($c) {
                $name = $c['Field'] ?? $c['field'] ?? null;
                $type = strtolower($c['Type'] ?? $c['type'] ?? '');
                if (!$name || !$type) return [];

                if (Str::contains($type, ['tinyint(1)', 'boolean', 'bool'])) {
                    return [$name => 'boolean'];
                }
                if (Str::contains($type, ['int', 'bigint', 'smallint', 'mediumint'])) {
                    return [$name => 'integer'];
                }
                if (Str::contains($type, ['decimal', 'numeric', 'float', 'double'])) {
                    return [$name => 'float'];
                }
                if (Str::contains($type, ['json'])) {
                    return [$name => 'array'];
                }
                if (Str::contains($type, ['datetime', 'timestamp', 'date'])) {
                    return [$name => 'datetime'];
                }

                return [];
            })
            ->toArray();
    }

    /**
     * Infer Validation Rules (Request Class)
     */
    public function inferValidationRules(array $columns, array $uniqueColumns = []): array
    {
        $rules = [];

        foreach ($columns as $c) {
            $name = $c['Field'] ?? $c['field'] ?? null;
            $type = strtolower($c['Type'] ?? $c['type'] ?? '');
            $null = ($c['Null'] ?? ($c['null'] ?? '')) === 'YES';

            if (!$name) continue;
            if (in_array($name, ['id', 'created_at', 'updated_at', 'deleted_at'], true)) continue;

            $parts = [];

            // Nullable / required
            $parts[] = $null ? 'nullable' : 'required';

            // Type-specific rules
            if (Str::contains($name, 'email')) {
                $parts[] = 'email';
                $parts[] = 'max:255';
            } elseif (Str::contains($name, 'password')) {
                $parts[] = 'string';
                $parts[] = 'min:8';
            } elseif (Str::contains($type, ['tinyint(1)', 'boolean', 'bool'])) {
                $parts[] = 'boolean';
            } elseif (Str::contains($type, ['int', 'bigint', 'smallint', 'mediumint'])) {
                $parts[] = 'integer';
            } elseif (Str::contains($type, ['decimal', 'numeric', 'float', 'double'])) {
                $parts[] = 'numeric';
            } elseif (Str::contains($type, ['date', 'datetime', 'timestamp'])) {
                $parts[] = 'date';
            } elseif (Str::contains($type, ['json'])) {
                $parts[] = 'array';
            } else {
                $parts[] = 'string';
                $parts[] = 'max:255';
            }

            // Unique rules only for unique columns
            if (isset($uniqueColumns[$name])) {
                $parts[] = 'unique';
            }

            $rules[$name] = $parts; // ✅ return as array
        }

        return $rules;
    }


    /**
     * Infer Relations (BelongsTo based on *_id fields)
     */
    public function inferRelations(array $columns): array
    {
        $relations = [];
        foreach ($columns as $c) {
            $name = $c['Field'] ?? $c['field'] ?? null;
            if ($name && Str::endsWith($name, '_id')) {
                $related = Str::studly(Str::singular(Str::beforeLast($name, '_id')));
                $relations[] = [
                    'name' => Str::beforeLast($name, '_id'),
                    'type' => 'belongsTo',
                    'model' => "App\\Models\\{$related}",
                    'resource' => "{$related}Resource"
                ];
            }
        }
        return $relations;
    }

    /**
     * Generate Filterable Fields for Controller
     */
    public function detectFilterable(array $columns): array
    {
        return collect($columns)
            ->map(fn($c) => $c['Field'] ?? $c['field'] ?? null)
            ->filter(fn($name) => $name && !in_array($name, ['id', 'created_at', 'updated_at', 'deleted_at'], true))
            ->values()
            ->toArray();
    }
}

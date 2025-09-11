<?php

namespace MavenOutline\AiGenerator\Services;

use Illuminate\Support\Str;

class PromptBuilder
{
    /**
     * Build a model prompt that includes inferred fillable, hidden and casts.
     *
     * @param string $className  PSR class name (e.g. User)
     * @param array  $columns    Array of column metadata (each item should have at least 'Field' and 'Type' keys)
     * @param array  $relationships Optional foreign key info
     * @return string
     */
    public function buildModelPrompt(string $className, array $columns, array $relationships = []): string
    {
        $table = $this->tableNameFromClass($className);
        $fillable = $this->detectFillable($columns);
        $hidden = $this->detectHidden($columns);
        $casts = $this->detectCasts($columns);
        $schema = $this->schemaSummary($columns);
        $rels = $this->relationshipsSummary($relationships);

        $fillablePhp = $this->asPhpArray($fillable);
        $hiddenPhp = $this->asPhpArray($hidden);
        $castsPhp = $this->asPhpArrayAssoc($casts);

        return <<<PROMPT
Generate a PSR-12 compliant Laravel Eloquent model class named `{$className}` in namespace `App\\Models`.

Requirements (strict):
- The model must explicitly set `protected \$table = '{$table}';`.
- The model must include `protected \$fillable = {$fillablePhp};`.
- The model must include `protected \$hidden = {$hiddenPhp};`.
- The model must include `protected \$casts = {$castsPhp};` (use appropriate Laravel cast types).
- Add relationship methods for the detected foreign keys: {$rels}. Use `belongsTo`, `hasMany`, etc., following Laravel conventions.
- Add docblocks for the model class and for properties where appropriate.
- Use correct imports and extend `Illuminate\\Database\\Eloquent\\Model`.
- Do NOT include any explanation or extra text.

Schema:
{$schema}

Return only the complete PHP file content enclosed in a php code block (```php ... ```) or starting with `<?php`.
PROMPT;
    }

    /**
     * Build a FormRequest prompt that requests validation rules inferred from column types.
     *
     * @param string $className
     * @param array $columns
     * @param array $relationships
     * @return string
     */
    public function buildRequestPrompt(string $className, array $columns, array $relationships = []): string
    {
        $table = $this->tableNameFromClass($className);
        $schema = $this->schemaSummary($columns);
        $rulesExamples = $this->inferValidationHints($columns);

        $rulesText = '';
        foreach ($rulesExamples as $field => $hint) {
            $rulesText .= "- `{$field}` => {$hint}\n";
        }

        return <<<PROMPT
Generate a Laravel FormRequest class named `{$className}Request` in namespace `App\\Http\\Requests`.

Requirements:
- Implement `authorize()` returning `true`.
- Implement `rules()` with validation rules inferred from the schema and examples below.
- Where applicable, include `required`, `string|email`, `max:<n>`, `integer`, `boolean`, `date`, `array`, and `unique:{$table},<column>` for unique columns.
- Use `sometimes` where fields are nullable.
- The returned code must be ready-to-use in a Laravel controller.
- Do NOT include any explanation or extra text.

Schema:
{$schema}

Suggested rules hints:
{$rulesText}

Return only the complete PHP file content enclosed in a php code block (```php ... ```) or starting with `<?php`.
PROMPT;
    }

    /**
     * Build a Resource prompt.
     *
     * @param string $className
     * @param array $columns
     * @param array $relationships
     * @return string
     */
    public function buildResourcePrompt(string $className, array $columns, array $relationships = []): string
    {
        $hidden = $this->detectHidden($columns);
        $schema = $this->schemaSummary($columns);
        $hiddenList = implode(', ', $hidden) ?: 'none';
        $relationshipsSummary = $this->relationshipsSummary($relationships);

        return <<<PROMPT
Generate a Laravel API Resource class named `{$className}Resource` in namespace `App\\Http\\Resources`.

Requirements:
- The resource must extend `Illuminate\\Http\\Resources\\Json\\JsonResource`.
- Implement `toArray(\$request)` returning an array of attributes.
- Map attributes explicitly — do NOT just return `parent::toArray(\$request)`.
- Exclude sensitive fields. Hidden fields detected: {$hiddenList}.
- Include relationships (`{$relationshipsSummary}`) as nested resources but load them conditionally using `whenLoaded()`.
- Follow PSR-12 and use correct imports.
- Return a clean, production-ready class.

Schema:
{$schema}

Return only the complete PHP file content enclosed in a php code block (```php ... ```) or starting with `<?php`.
PROMPT;
    }

    /**
     * Build a Service prompt.
     *
     * @param string $className
     * @param array $columns
     * @return string
     */
    public function buildServicePrompt(string $className, array $columns): string
    {
        $schema = $this->schemaSummary($columns);

        return <<<PROMPT
Generate a Service class named `{$className}Service` in namespace `App\\Services`.

Requirements:
- Implement methods: `paginate(array \$opts = [])`, `find(int \$id)`, `create(array \$data)`, `update(int \$id, array \$data)`, `delete(int \$id)`.
- Use Eloquent `{$className}` model for persistence.
- Use database transactions where appropriate in `create` and `update`.
- Return model instances or collections as appropriate.
- Handle not found exceptions by letting them bubble (use `findOrFail` in `find`/`update`/`delete` methods).

Schema:
{$schema}

Return only the complete PHP file content enclosed in a php code block (```php ... ```) or starting with `<?php`.
PROMPT;
    }

    /**
     * Build a Controller prompt.
     *
     * @param string $className
     * @param array $columns
     * @param array $relationships
     * @return string
     */
    public function buildControllerPrompt(string $className, array $columns, array $relationships = []): string
    {
        $resource = $className . 'Resource';
        $request = $className . 'Request';
        $service = $className . 'Service';
        $schema = $this->schemaSummary($columns);
        $table = $this->tableNameFromClass($className);

        return <<<PROMPT
Generate a Laravel API Controller named `{$className}Controller` in namespace `App\\Http\\Controllers\\Api`.

Requirements:
- Inject `{$service}` into the controller constructor.
- Methods: `index()`, `store({$request} \$request)`, `show(\$id)`, `update({$request} \$request, \$id)`, `destroy(\$id)`.
- Use `{$resource}` to return single/multiple resources.
- Use appropriate HTTP response codes (201 for created, 204 for deleted).
- Validate inputs via the provided FormRequest.
- Use the `{$service}` methods for business logic.

Schema:
{$schema}

Return only the complete PHP file content enclosed in a php code block (```php ... ```) or starting with `<?php`.
PROMPT;
    }

    /**
     * Build a short schema summary string from columns.
     *
     * @param array $cols
     * @return string
     */
    protected function schemaSummary(array $cols): string
    {
        $parts = [];
        foreach ($cols as $c) {
            $field = $c['Field'] ?? ($c['field'] ?? '');
            $type = $c['Type'] ?? ($c['type'] ?? '');
            $null = isset($c['Null']) ? ($c['Null'] === 'NO' ? 'NOT NULL' : 'NULL') : '';
            $parts[] = trim("{$field} {$type} {$null}");
        }
        return implode("; ", $parts);
    }

    /**
     * Return a human-friendly summary of relationships from information_schema rows.
     *
     * @param array $relationships
     * @return string
     */
    protected function relationshipsSummary(array $relationships): string
    {
        if (empty($relationships)) {
            return 'none';
        }
        $parts = [];
        foreach ($relationships as $r) {
            $col = $r['COLUMN_NAME'] ?? ($r['Column_name'] ?? ($r['column_name'] ?? ''));
            $refTable = $r['REFERENCED_TABLE_NAME'] ?? ($r['REFERENCED_TABLE'] ?? ($r['referenced_table_name'] ?? ''));
            $refCol = $r['REFERENCED_COLUMN_NAME'] ?? ($r['REFERENCED_COLUMN'] ?? ($r['referenced_column_name'] ?? ''));
            $parts[] = "{$col} -> {$refTable}({$refCol})";
        }
        return implode('; ', $parts);
    }

    /**
     * Detect fillable columns (exclude primary id and timestamps).
     *
     * @param array $columns
     * @return array
     */
    protected function detectFillable(array $columns): array
    {
        $excludes = ['id', 'created_at', 'updated_at', 'deleted_at'];
        $fillable = [];
        foreach ($columns as $c) {
            $name = $c['Field'] ?? ($c['field'] ?? null);
            if (!$name) {
                continue;
            }
            if (in_array($name, $excludes, true)) {
                continue;
            }
            $fillable[] = $name;
        }
        return array_values(array_unique($fillable));
    }

    /**
     * Detect hidden fields (passwords, tokens, secrets).
     *
     * @param array $columns
     * @return array
     */
    protected function detectHidden(array $columns): array
    {
        $sensitive = ['password', 'password_hash', 'remember_token', 'api_token', 'token', 'secret', 'access_token'];
        $hidden = [];
        foreach ($columns as $c) {
            $name = strtolower($c['Field'] ?? ($c['field'] ?? ''));
            foreach ($sensitive as $s) {
                if (Str::contains($name, $s)) {
                    $hidden[] = $c['Field'];
                    break;
                }
            }
        }
        return array_values(array_unique($hidden));
    }

    /**
     * Detect casts from column types.
     *
     * @param array $columns
     * @return array
     */
    protected function detectCasts(array $columns): array
    {
        $casts = [];
        foreach ($columns as $c) {
            $name = $c['Field'] ?? ($c['field'] ?? null);
            $type = strtolower($c['Type'] ?? ($c['type'] ?? ''));
            if (!$name || !$type) {
                continue;
            }
            if (Str::contains($type, ['tinyint(1)', 'boolean', 'bool'])) {
                $casts[$name] = 'boolean';
                continue;
            }
            if (Str::contains($type, ['int', 'bigint', 'smallint', 'mediumint'])) {
                $casts[$name] = 'integer';
                continue;
            }
            if (Str::contains($type, ['decimal', 'numeric', 'float', 'double'])) {
                $casts[$name] = 'float';
                continue;
            }
            if (Str::contains($type, ['json'])) {
                $casts[$name] = 'array';
                continue;
            }
            if (Str::contains($type, ['datetime', 'timestamp', 'date'])) {
                $casts[$name] = 'datetime';
                continue;
            }
        }
        return $casts;
    }

    /**
     * Produce a PHP array string like ['a','b'].
     *
     * @param array $arr
     * @return string
     */
    protected function asPhpArray(array $arr): string
    {
        if (empty($arr)) {
            return '[]';
        }
        $parts = array_map(function ($v) {
            return "'" . str_replace("'", "\\'", $v) . "'";
        }, $arr);
        return '[' . implode(', ', $parts) . ']';
    }

    /**
     * Produce a PHP associative array string like ['a' => 'integer'].
     *
     * @param array $assoc
     * @return string
     */
    protected function asPhpArrayAssoc(array $assoc): string
    {
        if (empty($assoc)) {
            return '[]';
        }
        $parts = [];
        foreach ($assoc as $k => $v) {
            $parts[] = "'" . str_replace("'", "\\'", $k) . "' => '" . str_replace("'", "\\'", $v) . "'";
        }
        return '[' . implode(', ', $parts) . ']';
    }

    /**
     * Infer simple validation hints (for prompt-level guidance).
     *
     * @param array $columns
     * @return array
     */
    protected function inferValidationHints(array $columns): array
    {
        $hints = [];
        foreach ($columns as $c) {
            $name = $c['Field'] ?? ($c['field'] ?? null);
            $type = strtolower($c['Type'] ?? ($c['type'] ?? ''));
            if (!$name) {
                continue;
            }

            // Skip meta columns
            if (in_array($name, ['id', 'created_at', 'updated_at', 'deleted_at', 'deleted'])) {
                continue;
            }

            // Basic heuristics
            if (Str::contains($name, ['email'])) {
                $hints[$name] = "'required|email|max:255|unique:'";
                continue;
            }
            if (Str::contains($name, ['password'])) {
                $hints[$name] = "'required|string|min:8|confirmed'";
                continue;
            }
            if (Str::contains($type, ['int', 'bigint', 'tinyint'])) {
                // tinyint(1) might represent boolean
                if (Str::contains($type, 'tinyint(1)') || Str::contains($type, 'boolean')) {
                    $hints[$name] = "'nullable|boolean'";
                } else {
                    $hints[$name] = "'nullable|integer'";
                }
                continue;
            }
            if (Str::contains($type, ['decimal', 'float', 'double', 'numeric'])) {
                $hints[$name] = "'nullable|numeric'";
                continue;
            }
            if (Str::contains($type, ['date', 'datetime', 'timestamp'])) {
                $hints[$name] = "'nullable|date'";
                continue;
            }
            if (Str::contains($type, ['json'])) {
                $hints[$name] = "'nullable|array'";
                continue;
            }

            // default to string
            $hints[$name] = "'nullable|string|max:255'";
        }
        return $hints;
    }

    /**
     * Convert className to table name using Laravel conventions.
     *
     * @param string $className
     * @return string
     */
    protected function tableNameFromClass(string $className): string
    {
        // e.g. UserProfile -> user_profiles
        return Str::snake(Str::plural($className));
    }
}

<?php
namespace MavenOutline\AiGenerator\Services;

use Illuminate\Support\Str;

class PromptBuilder
{
    public function detectFillable(array $columns): array
    {
        $excludes = ['id','created_at','updated_at','deleted_at'];
        $fillable = [];
        foreach ($columns as $c) {
            $name = $c['Field'] ?? $c['field'] ?? null;
            if (!$name) continue;
            if (in_array($name, $excludes, true)) continue;
            $fillable[] = $name;
        }
        return array_values(array_unique($fillable));
    }

    public function detectHidden(array $columns): array
    {
        $sensitive = ['password','password_hash','remember_token','api_token','token','secret','access_token'];
        $hidden = [];
        foreach ($columns as $c) {
            $name = strtolower($c['Field'] ?? $c['field'] ?? '');
            foreach ($sensitive as $s) {
                if (Str::contains($name, $s)) {
                    $hidden[] = $c['Field'] ?? $c['field'];
                    break;
                }
            }
        }
        return array_values(array_unique($hidden));
    }

    public function detectCasts(array $columns): array
    {
        $casts = [];
        foreach ($columns as $c) {
            $name = $c['Field'] ?? $c['field'] ?? null;
            $type = strtolower($c['Type'] ?? $c['type'] ?? '');
            if (!$name || !$type) continue;
            if (Str::contains($type, ['tinyint(1)','boolean','bool'])) { $casts[$name] = 'boolean'; continue; }
            if (Str::contains($type, ['int','bigint','smallint','mediumint'])) { $casts[$name] = 'integer'; continue; }
            if (Str::contains($type, ['decimal','numeric','float','double'])) { $casts[$name] = 'float'; continue; }
            if (Str::contains($type, ['json'])) { $casts[$name] = 'array'; continue; }
            if (Str::contains($type, ['datetime','timestamp','date'])) { $casts[$name] = 'datetime'; continue; }
        }
        return $casts;
    }

    public function inferValidationRules(array $columns, array $uniqueIndexes = []): array
    {
        $rules = [];
        foreach ($columns as $c) {
            $name = $c['Field'] ?? $c['field'] ?? null;
            $type = strtolower($c['Type'] ?? $c['type'] ?? '');
            $null = ($c['Null'] ?? ($c['null'] ?? '')) === 'YES';
            if (!$name) continue;
            if (in_array($name, ['id','created_at','updated_at','deleted_at'], true)) continue;
            $parts = [];
            if ($null) $parts[] = 'nullable'; else $parts[] = 'required';
            if (Str::contains($name, 'email')) { $parts[] = 'email'; $parts[] = 'max:255'; if (isset($uniqueIndexes[$name])) $parts[] = "unique:{{table}},{$name}"; $rules[$name] = implode('|',$parts); continue; }
            if (Str::contains($name, 'password')) { $parts[] = 'string'; $parts[] = 'min:8'; $rules[$name] = implode('|',$parts); continue; }
            if (Str::contains($type, ['tinyint(1)','boolean','bool'])) { $parts[] = 'boolean'; }
            elseif (Str::contains($type, ['int','bigint','smallint','mediumint'])) { $parts[] = 'integer'; }
            elseif (Str::contains($type, ['decimal','numeric','float','double'])) { $parts[] = 'numeric'; }
            elseif (Str::contains($type, ['date','datetime','timestamp'])) { $parts[] = 'date'; }
            elseif (Str::contains($type, ['json'])) { $parts[] = 'array'; }
            else { $parts[] = 'string'; $parts[] = 'max:255'; }
            if (isset($uniqueIndexes[$name])) $parts[] = "unique:{{table}},{$name}";
            $rules[$name] = implode('|',$parts);
        }
        return $rules;
    }

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

        return "Generate a PSR-12 compliant Eloquent model class named {$className} in namespace App\\Models.\n"
            . "Set protected $table='{$table}'; Set fillable={$fillablePhp}; hidden={$hiddenPhp}; casts={$castsPhp}.\n"
            . "Add relationship methods for: {$rels}. Return only the PHP file content.\nSchema: {$schema}";
    }

    public function buildRequestPrompt(string $className, array $columns, array $relationships = [], array $uniqueIndexes = []): string
    {
        $table = $this->tableNameFromClass($className);
        $rules = $this->inferValidationRules($columns, $uniqueIndexes);
        $rulesText = '';
        foreach ($rules as $f => $r) { $rulesText .= "'{$f}' => '{$r}',\n"; }
        return "Generate a FormRequest class named {$className}Request in namespace App\\Http\\Requests.\nInclude rules() using these examples (replace {{table}} with actual table name):\n{$rulesText}\nReturn only PHP file content.";
    }

    public function buildResourcePrompt(string $className, array $columns, array $relationships = []): string
    {
        $hidden = $this->detectHidden($columns);
        $fillable = $this->detectFillable($columns);
        $schema = $this->schemaSummary($columns);
        $hiddenList = implode(', ', $hidden) ?: 'none';
        $relsSummary = $this->relationshipsSummary($relationships);
        $fillableList = implode(', ', $fillable);
        return "Generate a JsonResource class named {$className}Resource in App\\Http\\Resources.\nMap fillable fields: {$fillableList}. Exclude hidden: {$hiddenList}. For relationships ({$relsSummary}), include them conditionally using whenLoaded(). Return only PHP file content.\nSchema: {$schema}";
    }

    public function buildServicePrompt(string $className, array $columns): string
    {
        $schema = $this->schemaSummary($columns);
        return "Generate a Service class named {$className}Service in App\\Services with standard CRUD methods using transactions where appropriate. Return only PHP file content. Schema: {$schema}";
    }

    public function buildControllerPrompt(string $className, array $columns, array $relationships = []): string
    {
        $schema = $this->schemaSummary($columns);
        return "Generate an API controller named {$className}Controller in App\\Http\\Controllers\\Api.\nInject {$className}Service. Methods: index, store({$className}Request), show, update({$className}Request), destroy. index should support pagination and filtering by fillable fields. Use {$className}Resource for responses. Return only PHP file content.\nSchema: {$schema}";
    }

    protected function schemaSummary(array $cols): string
    {
        $parts = [];
        foreach ($cols as $c) {
            $field = $c['Field'] ?? $c['field'] ?? '';
            $type = $c['Type'] ?? $c['type'] ?? '';
            $null = isset($c['Null']) ? ($c['Null'] === 'NO' ? 'NOT NULL' : 'NULL') : '';
            $parts[] = trim("{$field} {$type} {$null}");
        }
        return implode('; ', $parts);
    }

    protected function relationshipsSummary(array $relationships): string
    {
        if (empty($relationships)) return 'none';
        $parts = [];
        foreach ($relationships as $r) {
            $col = $r['COLUMN_NAME'] ?? $r['Column_name'] ?? $r['column_name'] ?? null;
            $ref = $r['REFERENCED_TABLE_NAME'] ?? $r['REFERENCED_TABLE'] ?? $r['referenced_table_name'] ?? null;
            $refCol = $r['REFERENCED_COLUMN_NAME'] ?? $r['REFERENCED_COLUMN'] ?? $r['referenced_column_name'] ?? 'id';
            if ($col && $ref) $parts[] = "{$col} -> {$ref}({$refCol})";
        }
        return implode('; ', $parts);
    }

    protected function asPhpArray(array $arr): string
    {
        if (empty($arr)) return '[]';
        $parts = array_map(function($v){ return "'".str_replace("'","\\'",$v)."'"; }, $arr);
        return '['.implode(', ', $parts).']';
    }

    protected function asPhpArrayAssoc(array $assoc): string
    {
        if (empty($assoc)) return '[]';
        $parts = [];
        foreach ($assoc as $k=>$v) { $parts[] = "'".str_replace("'","\\'",$k)."' => '".str_replace("'","\\'",$v)."'"; }
        return '['.implode(', ', $parts).']';
    }

    protected function tableNameFromClass(string $className): string
    {
        return Str::snake(Str::plural($className));
    }
}

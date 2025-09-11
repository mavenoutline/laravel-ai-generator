<?php

namespace MavenOutline\AiGenerator\Support;

use Illuminate\Support\Str;

class TemplateRenderer
{
    public static function stringList(array $items): string
    {
        if (empty($items)) return '';
        return implode(",\n        ", array_map(function ($v) {
            return "'" . addslashes($v) . "'";
        }, $items));
    }

    public static function arrayPhp(array $items): string
    {
        if (empty($items)) return '[]';
        return '[' . implode(', ', array_map(function ($v) {
            return "'" . addslashes($v) . "'";
        }, $items)) . ']';
    }

    public static function castsList(array $casts): string
    {
        if (empty($casts)) return '';
        $parts = [];
        foreach ($casts as $k => $v) $parts[] = "'" . addslashes($k) . "' => '" . addslashes($v) . "'";
        return implode(",\n        ", $parts);
    }

    public static function relationshipMethods(array $relationships): string
    {
        if (empty($relationships)) return '';
        $out = [];
        foreach ($relationships as $r) {
            $col = $r['COLUMN_NAME'] ?? $r['Column_name'] ?? $r['column_name'] ?? null;
            $ref = $r['REFERENCED_TABLE_NAME'] ?? $r['REFERENCED_TABLE'] ?? $r['referenced_table_name'] ?? null;
            $refCol = $r['REFERENCED_COLUMN_NAME'] ?? $r['REFERENCED_COLUMN'] ?? $r['referenced_column_name'] ?? 'id';
            if (!$col || !$ref) continue;
            $method = Str::camel(Str::before($col, '_')) ?: Str::camel($col);
            $relatedModel = Str::studly(Str::singular($ref));
            $out[] = "    /**\n     * BelongsTo relation auto-generated\n     */\n    public function {$method}()\n    {\n        return \$this->belongsTo(\\App\\Models\\{$relatedModel}::class, '{$col}', '{$refCol}');\n    }\n";
        }
        return implode("\n", $out);
    }

    public static function validationRulesList(array $rules, string $table): string
    {
        $lines = [];

        foreach ($rules as $column => $columnRules) {
            $formattedRules = [];

            foreach ($columnRules as $rule) {
                if ($rule === 'unique') {
                    // Only add unique rule for unique columns
                    $formattedRules[] = "Rule::unique('{$table}', '{$column}')->ignore(\$this->{$column})";
                } else {
                    $formattedRules[] = "'{$rule}'";
                }
            }

            $lines[] = "            '{$column}' => [" . implode(', ', $formattedRules) . "],";
        }

        return implode("\n", $lines);
    }

    public static function fillableMappings(array $fillable): string
    {
        if (empty($fillable)) return '';
        $parts = [];
        foreach ($fillable as $f) $parts[] = "'{$f}' => \$this->{$f},";
        return implode("\n            ", $parts);
    }

    public static function relationshipMappings(array $relationships): string
    {
        if (empty($relationships)) return '';
        $parts = [];
        foreach ($relationships as $r) {
            $col = $r['COLUMN_NAME'] ?? $r['Column_name'] ?? $r['column_name'] ?? null;
            $ref = $r['REFERENCED_TABLE_NAME'] ?? $r['REFERENCED_TABLE'] ?? $r['referenced_table_name'] ?? null;
            if (!$col || !$ref) continue;
            $method = Str::camel(Str::before($col, '_')) ?: Str::camel($col);
            $relatedResource = Str::studly(Str::singular($ref)) . 'Resource';
            $parts[] = "'{$method}' => \$this->whenLoaded('{$method}') ? new {$relatedResource}(\$this->{$method}) : null,";
        }
        return implode("\n            ", $parts);
    }

    public static function resourceImports(array $relationships): string
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

    public static function filterableArray(array $fillable): string
    {
        if (empty($fillable)) return '[]';
        return '[' . implode(', ', array_map(function ($f) {
            return "'" . addslashes($f) . "'";
        }, $fillable)) . ']';
    }

    public static function fileColumnsArrayFromColumns(array $columns): string
    {
        $fileCols = [];
        foreach ($columns as $c) {
            $name = $c['Field'] ?? $c['field'] ?? null;
            if (!$name) continue;
            if (preg_match('/(_file|_image|_avatar|_document)$/i', $name)) $fileCols[] = $name;
        }
        if (empty($fileCols)) return '[]';
        return '[' . implode(', ', array_map(function ($f) {
            return "'" . addslashes($f) . "'";
        }, $fileCols)) . ']';
    }
}

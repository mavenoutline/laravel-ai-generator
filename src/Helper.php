<?php

use Illuminate\Support\Str;

class Helper
{

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
        if (preg_match('/```php\s*(.*?)```/s', $raw, $m)) return trim($m[1]);
        if (preg_match('/```\s*(.*?)```/s', $raw, $m)) return trim($m[1]);
        if (preg_match('/(<\?php.*)/s', $raw, $m)) return trim($m[1]);
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
            $col = $r['COLUMN_NAME'] ?? $r['Column_name'] ?? $r['column_name'] ?? null;
            $ref = $r['REFERENCED_TABLE_NAME'] ?? $r['REFERENCED_TABLE'] ?? $r['referenced_table_name'] ?? null;
            $refCol = $r['REFERENCED_COLUMN_NAME'] ?? $r['REFERENCED_COLUMN'] ?? $r['referenced_column_name'] ?? 'id';
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

        $lines = [];
        foreach ($rules as $field => $r) {
            // r is string like 'required|email|unique:{{table}},email' or others
            // We'll convert to PHP array syntax, using Rule::unique when unique appears
            $parts = explode('|', $r);
            $phpParts = [];
            foreach ($parts as $p) {
                if (strpos($p, 'unique:') === 0) {
                    // format unique:table,column
                    $u = substr($p, 7);
                    if (strpos($u, ',') !== false) {
                        [$ut, $uc] = explode(',', $u, 2);
                    } else {
                        $ut = $table;
                        $uc = $field;
                    }
                    $phpParts[] = "Rule::unique('" . addslashes($ut) . "','" . addslashes($uc) . "')->ignore(\$id)";
                } else {
                    $phpParts[] = "'" . addslashes($p) . "'";
                }
            }
            $lines[] = "'" . $field . "' => [" . implode(', ', $phpParts) . "]";
        }
        return implode(",\n            ", $lines);
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
            $col = $r['COLUMN_NAME'] ?? $r['Column_name'] ?? $r['column_name'] ?? null;
            $ref = $r['REFERENCED_TABLE_NAME'] ?? $r['REFERENCED_TABLE'] ?? $r['referenced_table_name'] ?? null;
            if (!$col || !$ref) continue;
            $method = Str::camel(Str::before($col, '_'));
            $relatedResource = Str::studly(Str::singular($ref)) . 'Resource';
            // belongsTo -> single resource
            $parts[] = "'{$method}' => new {$relatedResource}(\$this->whenLoaded('{$method}')),";
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

<?php

namespace MavenOutline\AiGenerator\Services;

use Illuminate\Support\Facades\DB;

class SchemaInspector
{
    public function getColumns(string $table): array
    {
        try {
            $cols = DB::select("SHOW FULL COLUMNS FROM `{$table}`");
            return json_decode(json_encode($cols), true);
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function getForeignKeys(?string $database, string $table): array
    {
        try {
            if (!$database) return [];
            $sql = "SELECT CONSTRAINT_NAME, TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL";
            return json_decode(json_encode(DB::select($sql, [$database, $table])), true);
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function getUniqueColumns(?string $database, string $table): array
    {
        try {
            if (!$database) return [];
            $sql = "SELECT INDEX_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND NON_UNIQUE = 0";
            $rows = json_decode(json_encode(DB::select($sql, [$database, $table])), true);
            $unique = [];

            foreach ($rows as $r) {
                if (isset($r['COLUMN_NAME'])) $unique[$r['COLUMN_NAME']] = true;
            }
            return $unique;
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function resolveDatabaseName(): ?string
    {
        $defaultConn = config('database.default');
        if ($defaultConn && config("database.connections.{$defaultConn}.database")) {
            return config("database.connections.{$defaultConn}.database");
        }
        return env('DB_DATABASE') ?: null;
    }
}

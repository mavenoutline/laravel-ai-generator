<?php
namespace MavenOutline\AiGenerator\Support;

use Illuminate\Support\Str;

class ClassNameHelper
{
    public static function modelName(string $table): string
    {
        return Str::studly(Str::singular($table));
    }

    public static function controllerName(string $table): string
    {
        return self::modelName($table).'Controller';
    }
}

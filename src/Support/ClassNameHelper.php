<?php

namespace MavenOutline\AiGenerator\Support;

use Illuminate\Support\Str;

class ClassNameHelper
{
    public static function modelName(string $table): string
    {
        if (function_exists('str')) {
            return str($table)->singular()->studly()->toString();
        }
        return Str::studly(Str::singular($table));
    }

    public static function controllerName(string $table): string
    {
        return self::modelName($table) . 'Controller';
    }

    public static function serviceName(string $table): string
    {
        return self::modelName($table) . 'Service';
    }

    public static function requestName(string $table): string
    {
        return self::modelName($table) . 'Request';
    }

    public static function resourceName(string $table): string
    {
        return self::modelName($table) . 'Resource';
    }
}

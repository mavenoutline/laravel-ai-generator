<?php
namespace MavenOutline\AiGenerator\Services;

class CodeWriter
{
    public function write(string $relativePath, string $content, bool $force = false): bool
    {
        $full = $this->appBasePath($relativePath);
        $dir = dirname($full);
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        if (file_exists($full) && !$force) return false;
        file_put_contents($full, $content);
        return true;
    }

    protected function appBasePath(string $relative): string
    {
        if (function_exists('base_path')) return base_path($relative);
        if (function_exists('app') && method_exists(app(), 'basePath')) return app()->basePath() . DIRECTORY_SEPARATOR . $relative;
        return getcwd() . DIRECTORY_SEPARATOR . $relative;
    }
}

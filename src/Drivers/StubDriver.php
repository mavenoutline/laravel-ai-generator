<?php
namespace MavenOutline\AiGenerator\Drivers;

use MavenOutline\AiGenerator\Contracts\AiDriverContract;

class StubDriver implements AiDriverContract
{
    public function generate(string $prompt): string
    {
        return ''; // always fallback to templates
    }
}

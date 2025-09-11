<?php

namespace MavenOutline\AiGenerator\Drivers;

use MavenOutline\AiGenerator\Contracts\AiDriverContract;

class StubDriver implements AiDriverContract
{
    protected string $templatesPath;

    public function __construct(string $templatesPath = null)
    {
        $this->templatesPath = $templatesPath ?? __DIR__ . '/../../templates/';
    }

    public function generate(string $prompt): string
    {
        return '';
    }
}

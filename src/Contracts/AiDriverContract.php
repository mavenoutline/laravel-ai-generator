<?php
namespace MavenOutline\AiGenerator\Contracts;

interface AiDriverContract
{
    public function generate(string $prompt): string;
}

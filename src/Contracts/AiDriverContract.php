<?php

namespace MavenOutline\AiGenerator\Contracts;

interface AiDriverContract
{
    /**
     * Generate output from prompt. Return empty string on failure.
     *
     * @param string $prompt
     * @return string
     */
    public function generate(string $prompt): string;
}

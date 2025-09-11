<?php

namespace MavenOutline\AiGenerator\Contracts;

interface AiDriverContract
{
    /**
     * Generate code text based on a prompt.
     *
     * @param string $prompt
     * @return string PHP source or empty string on failure
     */
    public function generate(string $prompt): string;
}

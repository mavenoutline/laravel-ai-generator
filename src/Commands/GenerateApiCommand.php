<?php

namespace MavenOutline\AiGenerator\Commands;

use Illuminate\Console\Command;
use MavenOutline\AiGenerator\Services\GeneratorService;

class GenerateApiCommand extends Command
{
    protected $signature = 'ai:generate {tables*} {--force} {--no-ai}';
    protected $description = 'Generate API artifacts for given DB tables using configured AI provider.';

    protected GeneratorService $generator;

    public function __construct(GeneratorService $generator)
    {
        parent::__construct();
        $this->generator = $generator;
    }

    public function handle()
    {
        $tables = $this->argument('tables');
        $force = $this->option('force') || config('ai-generator.force_overwrite', false);
        $useAi = !$this->option('no-ai');

        foreach ($tables as $table) {
            $this->info("Processing table: {$table}");
            $generated = $this->generator->generateForTable($table, $useAi, $force);
            $this->info('Generated artifacts: ' . implode(', ', array_values($generated)));
        }

        return 0;
    }
}

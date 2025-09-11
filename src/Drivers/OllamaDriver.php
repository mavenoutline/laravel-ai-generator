<?php
namespace MavenOutline\AiGenerator\Drivers;

use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;
use MavenOutline\AiGenerator\Contracts\AiDriverContract;

class OllamaDriver implements AiDriverContract
{
    protected Client $client;
    protected string $endpoint;
    protected string $model;
    protected ?LoggerInterface $logger;

    
public function __construct(string $endpoint = null, string $model = null, LoggerInterface $logger = null)
{
    // Ensure non-null strings for endpoint and model.
    $configured = config('ai-generator.base_url', 'http://localhost:11434/api/generate');
    $this->endpoint = $endpoint ?: (is_string($configured) ? $configured : 'http://localhost:11434/api/generate');
    $this->model = $model ?: (string) config('ai-generator.model', 'codellama:latest');
    $this->logger = $logger;
    // Initialize Guzzle client without base_uri if endpoint is empty; prefer to set base_uri if valid URL.
    $clientOptions = ['timeout' => 120];
    if (!empty($this->endpoint)) {
        $clientOptions['base_uri'] = $this->endpoint;
    }
    $this->client = new Client($clientOptions);
}


    public function generate(string $prompt): string
    {
        try {
            $resp = $this->client->post('', ['json' => ['model' => $this->model, 'prompt' => $prompt, 'stream' => false]]);
            $body = (string) $resp->getBody();
            $data = json_decode($body, true);
            if (isset($data['response'])) return $data['response'];
            return $body;
        } catch (\Throwable $e) {
            if ($this->logger) $this->logger->error('OllamaDriver: '.$e->getMessage());
            return '';
        }
    }
}

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
        $this->endpoint = $endpoint ?? config('ai-generator.base_url', 'http://localhost:11434/api/generate');
        $this->model = $model ?? config('ai-generator.model', 'codellama:latest');
        $this->logger = $logger;
        $this->client = new Client(['base_uri' => $this->endpoint, 'timeout' => 120]);
    }

    public function generate(string $prompt): string
    {
        try {
            $payload = [
                'model' => $this->model,
                'prompt' => $prompt,
                'stream' => false
            ];

            $resp = $this->client->post('', ['json' => $payload, 'headers' => ['Accept' => 'application/json']]);
            $body = (string) $resp->getBody();
            $data = json_decode($body, true);
            if (isset($data['response'])) {
                return (string) $data['response'];
            }
            if (isset($data['generated'])) {
                return (string) $data['generated'];
            }
            return $body;
        } catch (\Throwable $e) {
            if ($this->logger) {
                $this->logger->error('OllamaDriver error: ' . $e->getMessage());
            }
            return '';
        }
    }
}

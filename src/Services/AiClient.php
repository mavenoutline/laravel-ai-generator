<?php

namespace MavenOutline\AiGenerator\Services;

use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;

class AiClient
{
    protected $client;
    protected $apiBase;
    protected $model;
    protected $provider;
    protected $logger;

    public function __construct($apiBase = 'http://localhost:11434', $model = 'codellama:latest', $provider = 'ollama', LoggerInterface $logger = null)
    {
        $this->apiBase = $apiBase;
        $this->model = $model;
        $this->provider = $provider;
        $this->logger = $logger;
        $this->client = new Client([
            'base_uri' => rtrim($this->apiBase, '/') . '/',
            'timeout' => 120,
        ]);
    }

    /**
     * Generate code from prompt.
     * Falls back to empty string on errors.
     */
    public function generate(string $prompt)
    {
        if ($this->provider === 'template') {
            return ''; // caller should use local templates when empty
        }

        try {
            // Ollama compatible endpoint
            $resp = $this->client->post('api/generate', [
                'json' => [
                    'model' => $this->model,
                    'prompt' => $prompt,
                    'stream' => false,
                    'template' => 'single',
                ],
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]);

            $body = (string) $resp->getBody();
            $data = json_decode($body, true);
            if (isset($data['response'])) {
                return $data['response'];
            }

            // older versions may stream plain text
            return $body;
        } catch (\Throwable $e) {
            if ($this->logger) {
                $this->logger->error('AI generation failed: ' . $e->getMessage());
            }
            return '';
        }
    }
}

# MavenOutline Lumen AI Generator

A Lumen package that generates full CRUD APIs (Model, Controller, FormRequest, Resource, Service, Routes) from a database table using a local AI model (recommended: Ollama + CodeLlama) with robust template fallbacks so generation works even without an LLM running locally.

---

## What it does
- Reads table schema from your database
- Generates Model, FormRequest (validation), Resource, Controller, Service classes
- Appends a `Route::apiResource(...)` line to your `routes/web.php`
- Uses a configured local AI provider (Ollama by default) to generate high-quality code, and falls back to built-in templates if AI is unavailable
- Provides configuration to define generation structure, naming conventions and template overrides

## Recommended local LLMs
- **Ollama** (recommended): local runner with an HTTP API (`POST /api/generate`) — good for Windows, Mac, Linux. See Ollama docs.  
- **CodeLlama**: code-specialized model available through Ollama or Hugging Face; good for code generation tasks.  
- **GPT4All**: desktop option for fully offline small-model usage on Windows.

(See links and references in the package README for setup instructions.)

## Installation

1. Install package via composer (from path or packagist when published):
```bash
composer require mavenoutline/laravel-ai-generator
```

2. Register the service provider in `bootstrap/app.php`:
```php
$app->register(MavenOutline\AiGenerator\AiGeneratorServiceProvider::class);
```

3. (Optional) Publish config & templates:
```bash
php artisan vendor:publish --provider="MavenOutline\AiGenerator\AiGeneratorServiceProvider"
```

4. Configure `.env`:
```
OLLAMA_API=http://localhost:11434
AI_PROVIDER=ollama
AI_MODEL=codellama:latest
```

5. Run generation:
```bash
php artisan ai:generate users
```

## Configuration options

See `config/ai-generator.php` for all options. You can define:
- provider (ollama|template)
- model (codellama:latest)
- templates path overrides
- naming rules (singular/plural)

## Why this design?
- Using a local LLM gives privacy and avoids cloud costs.
- Template fallback ensures the package is usable offline and safe from hallucination issues.

## Limitations & Testing
- This environment cannot run PHP/Lumen tests here — you must run `composer install` and `phpunit` locally to execute tests included in the package. The package includes PHPUnit tests and instructions to run them.

---

Detailed docs are included in this repository. Happy generating!


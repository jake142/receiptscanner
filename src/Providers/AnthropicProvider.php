<?php

declare(strict_types=1);

namespace Jake142\ReceiptScanner\Providers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Jake142\ReceiptScanner\Exceptions\ProviderException;
use Jake142\ReceiptScanner\Input\FileInput;
use Throwable;

class AnthropicProvider
{
    private const PROVIDER = 'anthropic';

    private const DEFAULT_BASE_URL = 'https://api.anthropic.com/v1';

    private const DEFAULT_VERSION = '2023-06-01';

    private const DEFAULT_MODEL = 'claude-sonnet-4-6';

    private const DEFAULT_MAX_TOKENS = 4096;

    /**
     * Send a receipt extraction request to Anthropic's Messages API.
     *
     * Expected context keys:
     * - prompt: JSON-only extraction or repair prompt
     * - files: array<FileInput> containing images, or one PDF document
     * - model: optional provider model override
     *
     * @param  array<string, mixed>  $context
     * @return array{text: string, provider: string, model: string, request_id: string|null, response_id: string|null, status: string|null}
     */
    public function extract(array $context): array
    {
        $apiKey = $this->apiKey();
        $model = $this->model($context);
        $payload = [
            'model' => $model,
            'max_tokens' => $this->maxTokens(),
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $this->contentBlocks($context),
                ],
            ],
        ];

        $response = $this->postWithRetries($payload, $apiKey);
        $body = $response->json();

        if (! is_array($body)) {
            throw $this->invalidResponse('Anthropic returned a non-JSON response.', $response->status(), $response);
        }

        $text = $this->extractText($body);

        if ($text === '') {
            throw $this->invalidResponse('Anthropic response did not contain a text content block.', $response->status(), $response);
        }

        return [
            'text' => $text,
            'provider' => self::PROVIDER,
            'model' => is_string($body['model'] ?? null) && $body['model'] !== '' ? $body['model'] : $model,
            'request_id' => $this->requestId($response),
            'response_id' => is_string($body['id'] ?? null) && $body['id'] !== '' ? $body['id'] : null,
            'status' => is_string($body['stop_reason'] ?? null) && $body['stop_reason'] !== '' ? $body['stop_reason'] : 'completed',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postWithRetries(array $payload, string $apiKey): Response
    {
        $attempts = max(1, (int) $this->config('receiptscanner.retries.attempts', 2));
        $baseDelayMs = max(0, (int) $this->config('receiptscanner.retries.base_delay_ms', 250));
        $timeout = max(1, (int) $this->config('receiptscanner.timeout', 60));
        $url = rtrim((string) $this->config('receiptscanner.providers.anthropic.base_url', self::DEFAULT_BASE_URL), '/') . '/messages';
        $version = (string) $this->config('receiptscanner.providers.anthropic.version', self::DEFAULT_VERSION);

        $lastConnectionException = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = Http::asJson()
                    ->acceptJson()
                    ->timeout($timeout)
                    ->withHeaders([
                        'x-api-key' => $apiKey,
                        'anthropic-version' => $version !== '' ? $version : self::DEFAULT_VERSION,
                    ])
                    ->post($url, $payload);
            } catch (ConnectionException $exception) {
                $lastConnectionException = $exception;

                if ($attempt >= $attempts) {
                    throw new ProviderException('Anthropic request failed due to a connection error.', 0, $exception);
                }

                $this->sleepBeforeRetry($attempt, $baseDelayMs);

                continue;
            }

            if ($response->successful()) {
                return $response;
            }

            if (! $this->isTransientStatus($response->status()) || $attempt >= $attempts) {
                throw $this->upstreamError($response);
            }

            $this->sleepBeforeRetry($attempt, $baseDelayMs);
        }

        throw new ProviderException('Anthropic request failed before a response was received.', 0, $lastConnectionException);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, mixed>>
     */
    private function contentBlocks(array $context): array
    {
        $prompt = (string) ($context['prompt'] ?? '');

        if (trim($prompt) === '') {
            throw new ProviderException('Anthropic extraction prompt cannot be empty.');
        }

        $blocks = [
            [
                'type' => 'text',
                'text' => $prompt,
            ],
        ];

        $files = $context['files'] ?? [];

        if (! is_array($files)) {
            throw new ProviderException('Anthropic provider context files must be an array.');
        }

        foreach ($files as $file) {
            $mime = $this->fileString($file, 'mime');
            $base64 = $this->fileString($file, 'base64');

            if ($mime === '' || $base64 === '') {
                throw new ProviderException('Anthropic file input must include MIME type and base64 data.');
            }

            if (str_starts_with($mime, 'image/')) {
                $blocks[] = [
                    'type' => 'image',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => $mime,
                        'data' => $base64,
                    ],
                ];

                continue;
            }

            if ($mime === 'application/pdf') {
                $blocks[] = [
                    'type' => 'document',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => 'application/pdf',
                        'data' => $base64,
                    ],
                ];

                continue;
            }

            throw new ProviderException('Anthropic only supports image inputs or a single PDF document for receipt scanning.');
        }

        return $blocks;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function extractText(array $body): string
    {
        $content = $body['content'] ?? null;

        if (! is_array($content)) {
            return '';
        }

        $parts = [];

        foreach ($content as $block) {
            if (! is_array($block)) {
                continue;
            }

            if (($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $parts[] = $block['text'];
            }
        }

        return trim(implode("\n", $parts));
    }

    private function apiKey(): string
    {
        $apiKey = (string) $this->config('receiptscanner.providers.anthropic.api_key', '');

        if ($apiKey === '') {
            throw new ProviderException('Anthropic API key is not configured. Set ANTHROPIC_API_KEY or receiptscanner.providers.anthropic.api_key.');
        }

        return $apiKey;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function model(array $context): string
    {
        $contextModel = $context['model'] ?? null;

        if (is_string($contextModel) && trim($contextModel) !== '') {
            return trim($contextModel);
        }

        $configuredModel = (string) $this->config(
            'receiptscanner.model',
            $this->config('receiptscanner.providers.anthropic.default_model', self::DEFAULT_MODEL)
        );

        return trim($configuredModel) !== '' ? trim($configuredModel) : self::DEFAULT_MODEL;
    }

    private function maxTokens(): int
    {
        return max(1, (int) $this->config('receiptscanner.providers.anthropic.max_tokens', self::DEFAULT_MAX_TOKENS));
    }

    private function requestId(Response $response): ?string
    {
        foreach (['request-id', 'x-request-id', 'anthropic-request-id'] as $header) {
            $value = $response->header($header);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function isTransientStatus(int $status): bool
    {
        return $status === 429 || $status >= 500;
    }

    private function sleepBeforeRetry(int $attempt, int $baseDelayMs): void
    {
        if ($baseDelayMs <= 0) {
            return;
        }

        $delayMs = min(5000, $baseDelayMs * (2 ** max(0, $attempt - 1)));
        usleep($delayMs * 1000);
    }

    private function upstreamError(Response $response): ProviderException
    {
        $status = $response->status();
        $body = $response->json();
        $message = 'Anthropic request failed.';

        if (is_array($body)) {
            $error = $body['error'] ?? null;

            if (is_array($error)) {
                $type = is_scalar($error['type'] ?? null) ? (string) $error['type'] : null;
                $errorMessage = is_scalar($error['message'] ?? null) ? (string) $error['message'] : null;

                if ($type !== null && $type !== '') {
                    $message .= ' type=' . $type . '.';
                }

                if ($errorMessage !== null && $errorMessage !== '') {
                    $message .= ' message=' . $errorMessage;
                }
            }
        }

        return new ProviderException(trim($message) . ' HTTP status ' . $status . '.', $status);
    }

    private function invalidResponse(string $message, int $status, ?Response $response = null): ProviderException
    {
        $requestId = $response instanceof Response ? $this->requestId($response) : null;

        if ($requestId !== null) {
            $message .= ' request_id=' . $requestId . '.';
        }

        return new ProviderException($message, $status);
    }

    /**
     * @param  mixed  $file
     */
    private function fileString(mixed $file, string $property): string
    {
        $value = null;

        if ($file instanceof FileInput) {
            $value = $file->{$property};
        } elseif (is_object($file) && property_exists($file, $property)) {
            $value = $file->{$property};
        } elseif (is_array($file) && array_key_exists($property, $file)) {
            $value = $file[$property];
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param  mixed  $default
     * @return mixed
     */
    private function config(string $key, mixed $default = null): mixed
    {
        if (function_exists('config')) {
            return config($key, $default);
        }

        return $default;
    }
}

<?php

declare(strict_types=1);

namespace Jake142\ReceiptScanner\Providers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Jake142\ReceiptScanner\Exceptions\ProviderException;
use Throwable;

class GeminiProvider
{
    private const PROVIDER = 'gemini';

    /**
     * Send a normalized receipt extraction or repair request to Gemini.
     *
     * Expected context keys:
     * - prompt: JSON-only extraction or repair prompt text
     * - files: array of FileInput instances for extraction; may be empty for repair
     * - model: optional Gemini model override
     *
     * @param array<string, mixed> $context
     * @return array{text: string, provider: string, model: string, request_id: ?string, response_id: ?string, status: int}
     */
    public function extract(array $context): array
    {
        $model = $this->model($context);
        $apiKey = $this->apiKey();
        $url = $this->endpointUrl($model);
        $payload = $this->payload($context);

        $response = $this->sendWithRetries($url, $apiKey, $payload, $model);
        $body = $response->json();

        if (! is_array($body)) {
            throw $this->failure('Gemini returned a non-JSON response.', $response->status(), [
                'provider' => self::PROVIDER,
                'model' => $model,
                'request_id' => $this->requestId($response),
            ]);
        }

        $text = $this->candidateText($body);

        if ($text === '') {
            throw $this->failure('Gemini response did not contain candidate text.', $response->status(), [
                'provider' => self::PROVIDER,
                'model' => $model,
                'request_id' => $this->requestId($response),
                'response_id' => $this->responseId($body),
                'finish_reason' => $this->finishReason($body),
                'block_reason' => $this->blockReason($body),
            ]);
        }

        return [
            'text' => $text,
            'provider' => self::PROVIDER,
            'model' => $model,
            'request_id' => $this->requestId($response),
            'response_id' => $this->responseId($body),
            'status' => $response->status(),
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function payload(array $context): array
    {
        $prompt = trim((string) ($context['prompt'] ?? ''));

        if ($prompt === '') {
            throw $this->failure('Gemini prompt is empty.', null, [
                'provider' => self::PROVIDER,
            ]);
        }

        $parts = [[
            'text' => $prompt,
        ]];

        foreach ($this->files($context) as $file) {
            $mime = $this->fileMime($file);
            $base64 = $this->fileBase64($file);

            if ($mime === '' || $base64 === '') {
                throw $this->failure('Gemini file part is missing MIME type or base64 data.', null, [
                    'provider' => self::PROVIDER,
                ]);
            }

            $parts[] = [
                'inline_data' => [
                    'mime_type' => $mime,
                    'data' => $base64,
                ],
            ];
        }

        return [
            'contents' => [[
                'role' => 'user',
                'parts' => $parts,
            ]],
            'generationConfig' => [
                'temperature' => 0,
                'response_mime_type' => 'application/json',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<int, mixed>
     */
    private function files(array $context): array
    {
        $files = $context['files'] ?? [];

        if (! is_array($files)) {
            return [];
        }

        return array_values($files);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sendWithRetries(string $url, string $apiKey, array $payload, string $model): Response
    {
        $attempts = max(1, (int) $this->config('receiptscanner.retries.attempts', 1));
        $delayMs = max(0, (int) $this->config('receiptscanner.retries.base_delay_ms', 250));
        $timeout = max(1, (int) $this->config('receiptscanner.timeout', 60));
        $lastConnectionException = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = Http::timeout($timeout)
                    ->connectTimeout(min(10, $timeout))
                    ->acceptJson()
                    ->asJson()
                    ->withHeaders([
                        'x-goog-api-key' => $apiKey,
                    ])
                    ->post($url, $payload);
            } catch (ConnectionException $exception) {
                $lastConnectionException = $exception;

                if ($attempt < $attempts) {
                    $this->sleepBeforeRetry($delayMs);
                    continue;
                }

                throw $this->failure('Gemini request failed before receiving a response.', null, [
                    'provider' => self::PROVIDER,
                    'model' => $model,
                ], $exception);
            }

            if ($this->isTransientStatus($response->status()) && $attempt < $attempts) {
                $this->sleepBeforeRetry($delayMs);
                continue;
            }

            if ($response->failed()) {
                throw $this->httpFailure($response, $model);
            }

            return $response;
        }

        throw $this->failure('Gemini request failed after retry attempts.', null, [
            'provider' => self::PROVIDER,
            'model' => $model,
        ], $lastConnectionException);
    }

    private function httpFailure(Response $response, string $model): ProviderException
    {
        $body = $response->json();
        $errorCode = null;
        $errorStatus = null;

        if (is_array($body) && isset($body['error']) && is_array($body['error'])) {
            $errorCode = isset($body['error']['code']) && is_scalar($body['error']['code'])
                ? (string) $body['error']['code']
                : null;
            $errorStatus = isset($body['error']['status']) && is_scalar($body['error']['status'])
                ? (string) $body['error']['status']
                : null;
        }

        return $this->failure('Gemini request was rejected by the upstream provider.', $response->status(), [
            'provider' => self::PROVIDER,
            'model' => $model,
            'request_id' => $this->requestId($response),
            'error_code' => $errorCode,
            'error_status' => $errorStatus,
        ]);
    }

    private function isTransientStatus(int $status): bool
    {
        return $status === 429 || $status >= 500;
    }

    private function sleepBeforeRetry(int $delayMs): void
    {
        if ($delayMs <= 0) {
            return;
        }

        usleep($delayMs * 1000);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function candidateText(array $body): string
    {
        $candidates = $body['candidates'] ?? null;

        if (! is_array($candidates) || ! isset($candidates[0]) || ! is_array($candidates[0])) {
            return '';
        }

        $content = $candidates[0]['content'] ?? null;

        if (! is_array($content)) {
            return '';
        }

        $parts = $content['parts'] ?? null;

        if (! is_array($parts)) {
            return '';
        }

        $texts = [];

        foreach ($parts as $part) {
            if (is_array($part) && isset($part['text']) && is_string($part['text']) && trim($part['text']) !== '') {
                $texts[] = $part['text'];
            }
        }

        return trim(implode("\n", $texts));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function responseId(array $body): ?string
    {
        foreach (['responseId', 'response_id'] as $key) {
            if (isset($body[$key]) && is_scalar($body[$key]) && (string) $body[$key] !== '') {
                return (string) $body[$key];
            }
        }

        return null;
    }

    private function requestId(Response $response): ?string
    {
        foreach (['x-request-id', 'x-goog-request-id', 'request-id'] as $header) {
            $value = $response->header($header);

            if (is_array($value)) {
                $value = $value[0] ?? null;
            }

            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function finishReason(array $body): ?string
    {
        $reason = $body['candidates'][0]['finishReason'] ?? $body['candidates'][0]['finish_reason'] ?? null;

        return is_scalar($reason) && (string) $reason !== '' ? (string) $reason : null;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function blockReason(array $body): ?string
    {
        $reason = $body['promptFeedback']['blockReason'] ?? $body['prompt_feedback']['block_reason'] ?? null;

        return is_scalar($reason) && (string) $reason !== '' ? (string) $reason : null;
    }

    private function apiKey(): string
    {
        $apiKey = trim((string) $this->config('receiptscanner.providers.gemini.api_key', ''));

        if ($apiKey === '') {
            throw $this->failure('Gemini API key is not configured. Set GEMINI_API_KEY or receiptscanner.providers.gemini.api_key.', null, [
                'provider' => self::PROVIDER,
            ]);
        }

        return $apiKey;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function model(array $context): string
    {
        $model = trim((string) ($context['model'] ?? ''));

        if ($model !== '') {
            return $model;
        }

        $configuredModel = trim((string) $this->config('receiptscanner.model', ''));

        if ($configuredModel !== '') {
            return $configuredModel;
        }

        $providerDefault = trim((string) $this->config('receiptscanner.providers.gemini.default_model', ''));

        return $providerDefault !== '' ? $providerDefault : 'gemini-3.5-flash';
    }

    private function endpointUrl(string $model): string
    {
        $baseUrl = rtrim((string) $this->config(
            'receiptscanner.providers.gemini.base_url',
            'https://generativelanguage.googleapis.com/v1beta'
        ), '/');

        $modelPath = str_starts_with($model, 'models/') ? $model : 'models/' . $model;
        $modelPath = implode('/', array_map('rawurlencode', explode('/', $modelPath)));

        return $baseUrl . '/' . $modelPath . ':generateContent';
    }

    private function fileMime(mixed $file): string
    {
        if (is_object($file) && isset($file->mime) && is_scalar($file->mime)) {
            return (string) $file->mime;
        }

        if (is_array($file) && isset($file['mime']) && is_scalar($file['mime'])) {
            return (string) $file['mime'];
        }

        return '';
    }

    private function fileBase64(mixed $file): string
    {
        if (is_object($file) && isset($file->base64) && is_scalar($file->base64)) {
            return (string) $file->base64;
        }

        if (is_array($file) && isset($file['base64']) && is_scalar($file['base64'])) {
            return (string) $file['base64'];
        }

        return '';
    }

    private function config(string $key, mixed $default = null): mixed
    {
        if (function_exists('config')) {
            return config($key, $default);
        }

        return $default;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function failure(string $message, ?int $status = null, array $context = [], ?Throwable $previous = null): ProviderException
    {
        $details = [];

        foreach ($context as $key => $value) {
            if (! is_scalar($value) || $value === '') {
                continue;
            }

            $details[] = $key . '=' . (string) $value;
        }

        if ($status !== null) {
            array_unshift($details, 'status=' . $status);
        }

        if ($details !== []) {
            $message .= ' (' . implode(', ', $details) . ')';
        }

        return new ProviderException($message, $status ?? 0, $previous);
    }
}

<?php

declare(strict_types=1);

namespace Jake142\ReceiptScanner\Providers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Jake142\ReceiptScanner\Exceptions\ProviderException;
use Throwable;

class AzureOpenAiProvider
{
    private const PROVIDER = 'azure_openai';
    private const DEFAULT_MODEL = 'gpt-5.4-nano';

    /**
     * Send a receipt extraction request to the Azure OpenAI v1-style Responses API.
     *
     * @param array<string, mixed> $context
     * @return array{text: string, provider: string, model: string, request_id: ?string, response_id: ?string, status: int|string|null}
     */
    public function extract(array $context): array
    {
        $apiKey = $this->stringConfig('receiptscanner.providers.azure_openai.api_key');

        if ($apiKey === '') {
            throw new ProviderException('Azure OpenAI API key is not configured.');
        }

        $url = $this->responsesUrl();
        $model = $this->model($context);
        $payload = $this->payload($context, $model);
        $timeout = max(1, (int) $this->configValue('receiptscanner.timeout', 60));
        $attempts = max(1, (int) $this->configValue('receiptscanner.retries.attempts', 1));
        $baseDelayMs = max(0, (int) $this->configValue('receiptscanner.retries.base_delay_ms', 250));

        $response = $this->postWithRetries($url, $apiKey, $payload, $timeout, $attempts, $baseDelayMs);

        if (! $response->successful()) {
            $this->throwForErrorResponse($response);
        }

        $decoded = json_decode($response->body(), true);

        if (! is_array($decoded)) {
            throw new ProviderException($this->message('Azure OpenAI returned an invalid JSON response.', $response));
        }

        $text = $decoded['output_text'] ?? null;

        if (! is_string($text)) {
            throw new ProviderException($this->message('Azure OpenAI response did not contain output_text.', $response));
        }

        return [
            'text' => $text,
            'provider' => self::PROVIDER,
            'model' => $model,
            'request_id' => $this->requestId($response),
            'response_id' => isset($decoded['id']) && is_scalar($decoded['id']) ? (string) $decoded['id'] : null,
            'status' => isset($decoded['status']) && is_scalar($decoded['status']) ? (string) $decoded['status'] : $response->status(),
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function payload(array $context, string $model): array
    {
        $prompt = isset($context['prompt']) && is_scalar($context['prompt']) ? trim((string) $context['prompt']) : '';

        if ($prompt === '') {
            throw new ProviderException('Azure OpenAI extraction prompt is empty.');
        }

        $content = [];
        $files = isset($context['files']) && is_array($context['files']) ? $context['files'] : [];

        foreach ($files as $file) {
            $content[] = $this->fileContentPart($file);
        }

        $content[] = [
            'type' => 'input_text',
            'text' => $prompt,
        ];

        return [
            'model' => $model,
            'input' => [
                [
                    'role' => 'user',
                    'content' => $content,
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'receipt_result',
                    'strict' => true,
                    'schema' => $this->jsonSchema($this->enabledSections($context)),
                ],
            ],
        ];
    }

    /**
     * @param mixed $file
     * @return array<string, string>
     */
    private function fileContentPart(mixed $file): array
    {
        $mime = strtolower($this->fileString($file, 'mime'));
        $filename = $this->fileString($file, 'filename') ?: ($mime === 'application/pdf' ? 'receipt.pdf' : 'receipt-image');
        $dataUri = $this->dataUri($file, $mime);

        if ($mime === 'application/pdf') {
            return [
                'type' => 'input_file',
                'filename' => $filename,
                'file_data' => $dataUri,
            ];
        }

        if (in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return [
                'type' => 'input_image',
                'image_url' => $dataUri,
            ];
        }

        throw new ProviderException('Azure OpenAI supports receipt images as JPEG, PNG, or WebP, and receipts as a single PDF.');
    }

    private function dataUri(mixed $file, string $mime): string
    {
        $dataUri = $this->fileString($file, 'data_uri');

        if ($dataUri !== '') {
            return $dataUri;
        }

        $base64 = $this->fileString($file, 'base64');

        if ($mime === '' || $base64 === '') {
            throw new ProviderException('Azure OpenAI file input is missing MIME type or base64 data.');
        }

        return 'data:' . $mime . ';base64,' . $base64;
    }

    /**
     * @param mixed $file
     */
    private function fileString(mixed $file, string $key): string
    {
        if (is_array($file) && array_key_exists($key, $file) && is_scalar($file[$key])) {
            return (string) $file[$key];
        }

        if (is_object($file) && isset($file->{$key}) && is_scalar($file->{$key})) {
            return (string) $file->{$key};
        }

        return '';
    }

    /**
     * @param array<string, mixed> $context
     */
    private function model(array $context): string
    {
        $contextModel = isset($context['model']) && is_scalar($context['model']) ? trim((string) $context['model']) : '';

        if ($contextModel !== '') {
            return $contextModel;
        }

        foreach ([
            'receiptscanner.providers.azure_openai.deployment',
            'receiptscanner.model',
            'receiptscanner.providers.azure_openai.default_model',
        ] as $key) {
            $value = $this->stringConfig($key);

            if ($value !== '') {
                return $value;
            }
        }

        return self::DEFAULT_MODEL;
    }

    private function responsesUrl(): string
    {
        $endpoint = $this->stringConfig('receiptscanner.providers.azure_openai.endpoint');

        if ($endpoint === '') {
            throw new ProviderException('Azure OpenAI endpoint is not configured.');
        }

        $endpoint = trim($endpoint);
        $query = '';

        if (str_contains($endpoint, '?')) {
            [$endpoint, $query] = explode('?', $endpoint, 2);
            $query = '?' . $query;
        }

        $endpoint = rtrim($endpoint, '/');

        if (str_ends_with($endpoint, '/responses')) {
            return $endpoint . $query;
        }

        if (str_contains($endpoint, '/openai/v1')) {
            return $endpoint . '/responses' . $query;
        }

        return $endpoint . '/openai/v1/responses' . $query;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function postWithRetries(string $url, string $apiKey, array $payload, int $timeout, int $attempts, int $baseDelayMs): Response
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = Http::timeout($timeout)
                    ->acceptJson()
                    ->asJson()
                    ->withHeaders([
                        'api-key' => $apiKey,
                        'Content-Type' => 'application/json',
                    ])
                    ->post($url, $payload);

                if (! $this->isTransientStatus($response->status()) || $attempt >= $attempts) {
                    return $response;
                }
            } catch (ConnectionException $exception) {
                $lastException = $exception;

                if ($attempt >= $attempts) {
                    throw new ProviderException('Azure OpenAI request failed due to a connection error.', 0, $exception);
                }
            } catch (Throwable $exception) {
                throw new ProviderException('Azure OpenAI request failed before receiving a response.', 0, $exception);
            }

            $this->sleepBeforeRetry($attempt, $baseDelayMs);
        }

        throw new ProviderException('Azure OpenAI request failed due to a connection error.', 0, $lastException);
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

        $delayMs = $baseDelayMs * (2 ** max(0, $attempt - 1));
        usleep($delayMs * 1000);
    }

    private function throwForErrorResponse(Response $response): never
    {
        $decoded = json_decode($response->body(), true);
        $upstreamMessage = null;
        $upstreamCode = null;

        if (is_array($decoded)) {
            $message = $decoded['error']['message'] ?? $decoded['message'] ?? null;
            $code = $decoded['error']['code'] ?? $decoded['code'] ?? null;
            $upstreamMessage = is_scalar($message) ? $this->sanitize((string) $message) : null;
            $upstreamCode = is_scalar($code) ? $this->sanitize((string) $code) : null;
        }

        $message = $this->message('Azure OpenAI request failed.', $response);

        if ($upstreamCode !== null && $upstreamCode !== '') {
            $message .= ' upstream_code=' . $upstreamCode;
        }

        if ($upstreamMessage !== null && $upstreamMessage !== '') {
            $message .= ' upstream_message=' . $this->limit($upstreamMessage, 300);
        }

        throw new ProviderException($message, $response->status());
    }

    private function message(string $message, Response $response): string
    {
        $parts = [$message, 'status=' . $response->status()];
        $requestId = $this->requestId($response);

        if ($requestId !== null && $requestId !== '') {
            $parts[] = 'request_id=' . $this->sanitize($requestId);
        }

        return implode(' ', $parts);
    }

    private function requestId(Response $response): ?string
    {
        foreach (['x-ms-request-id', 'apim-request-id', 'x-request-id'] as $header) {
            $value = $response->header($header);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $context
     * @return list<string>
     */
    private function enabledSections(array $context): array
    {
        $known = ['merchant', 'receipt', 'totals', 'vat_breakdown', 'line_items', 'mcc', 'confidence', 'warnings', 'metadata'];
        $sections = isset($context['enabled_sections']) && is_array($context['enabled_sections'])
            ? $this->stringList($context['enabled_sections'])
            : [];

        if ($sections === []) {
            foreach ($known as $section) {
                if ((bool) $this->configValue('receiptscanner.fields.' . $section, true)) {
                    $sections[] = $section;
                }
            }
        }

        $excluded = [];

        if (isset($context['excluded_sections']) && is_array($context['excluded_sections'])) {
            $excluded = $this->stringList($context['excluded_sections']);
        } else {
            $configured = $this->configValue('receiptscanner.exclude', []);
            $excluded = is_array($configured) ? $this->stringList($configured) : $this->stringList(explode(',', (string) $configured));
        }

        $sections = array_values(array_unique(array_filter($sections, static function (string $section) use ($known, $excluded): bool {
            return in_array($section, $known, true) && ! in_array($section, $excluded, true);
        })));

        return $sections;
    }

    /**
     * @param array<int|string, mixed> $values
     * @return list<string>
     */
    private function stringList(array $values): array
    {
        $strings = [];

        foreach ($values as $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $value = trim((string) $value);

            if ($value !== '') {
                $strings[] = $value;
            }
        }

        return $strings;
    }

    /**
     * Build an OpenAI Responses-compatible strict JSON Schema for the enabled receipt sections.
     *
     * @param list<string> $sections
     * @return array<string, mixed>
     */
    private function jsonSchema(array $sections): array
    {
        $properties = [
            'schema_version' => [
                'type' => 'string',
                'description' => 'Receipt extraction schema version.',
            ],
        ];
        $required = ['schema_version'];

        foreach ($sections as $section) {
            $properties[$section] = $this->sectionSchema($section);
            $required[] = $section;
        }

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => $properties,
            'required' => $required,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sectionSchema(string $section): array
    {
        return match ($section) {
            'merchant' => $this->objectSchema([
                'name' => $this->nullableString(),
                'address' => $this->nullableString(),
                'phone' => $this->nullableString(),
                'email' => $this->nullableString(),
                'tax_id' => $this->nullableString(),
            ]),
            'receipt' => $this->objectSchema([
                'receipt_number' => $this->nullableString(),
                'date' => $this->nullableString(),
                'time' => $this->nullableString(),
                'currency' => $this->nullableString(),
                'payment_method' => $this->nullableString(),
                'card_last4' => $this->nullableString(),
            ]),
            'totals' => $this->objectSchema([
                'subtotal' => $this->nullableNumber(),
                'discount' => $this->nullableNumber(),
                'shipping' => $this->nullableNumber(),
                'tip' => $this->nullableNumber(),
                'tax' => $this->nullableNumber(),
                'total' => $this->nullableNumber(),
                'paid' => $this->nullableNumber(),
                'change_due' => $this->nullableNumber(),
            ]),
            'vat_breakdown' => [
                'type' => 'array',
                'items' => $this->objectSchema([
                    'rate' => $this->nullableNumber(),
                    'net' => $this->nullableNumber(),
                    'tax' => $this->nullableNumber(),
                    'gross' => $this->nullableNumber(),
                ]),
            ],
            'line_items' => [
                'type' => 'array',
                'items' => $this->objectSchema([
                    'description' => $this->nullableString(),
                    'quantity' => $this->nullableNumber(),
                    'unit_price' => $this->nullableNumber(),
                    'total' => $this->nullableNumber(),
                    'sku' => $this->nullableString(),
                    'category' => $this->nullableString(),
                    'tax_rate' => $this->nullableNumber(),
                ]),
            ],
            'mcc' => $this->objectSchema([
                'code' => $this->nullableString(),
                'description' => $this->nullableString(),
            ]),
            'confidence' => $this->objectSchema([
                'overall' => $this->nullableNumber(),
                'merchant' => $this->nullableNumber(),
                'receipt' => $this->nullableNumber(),
                'totals' => $this->nullableNumber(),
                'line_items' => $this->nullableNumber(),
            ]),
            'warnings' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
            ],
            'metadata' => $this->objectSchema([
                'language' => $this->nullableString(),
                'country' => $this->nullableString(),
                'source_pages' => ['type' => ['integer', 'null']],
                'source_images' => ['type' => ['integer', 'null']],
                'notes' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ]),
            default => [
                'type' => ['object', 'null'],
                'additionalProperties' => false,
                'properties' => (object) [],
                'required' => [],
            ],
        };
    }

    /**
     * @param array<string, array<string, mixed>> $properties
     * @return array<string, mixed>
     */
    private function objectSchema(array $properties): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => $properties,
            'required' => array_keys($properties),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function nullableString(): array
    {
        return ['type' => ['string', 'null']];
    }

    /**
     * @return array<string, mixed>
     */
    private function nullableNumber(): array
    {
        return ['type' => ['number', 'null']];
    }

    private function stringConfig(string $key): string
    {
        $value = $this->configValue($key, '');

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function configValue(string $key, mixed $default = null): mixed
    {
        if (function_exists('config')) {
            return config($key, $default);
        }

        return $default;
    }

    private function sanitize(string $value): string
    {
        $value = preg_replace('/data:[^\s,;]+;base64,[A-Za-z0-9+\/=\r\n_-]+/i', '[redacted-data-uri]', $value) ?? $value;
        $value = preg_replace('/(api-key|authorization|bearer)\s*[:=]\s*[^\s,;]+/i', '$1=[redacted]', $value) ?? $value;

        return trim(str_replace(["\r", "\n"], ' ', $value));
    }

    private function limit(string $value, int $length): string
    {
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($value) > $length ? mb_substr($value, 0, $length) . '…' : $value;
        }

        return strlen($value) > $length ? substr($value, 0, $length) . '…' : $value;
    }
}

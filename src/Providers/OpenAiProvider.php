<?php

declare(strict_types=1);

namespace Jake142\ReceiptScanner\Providers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Jake142\ReceiptScanner\Exceptions\ProviderException;
use Throwable;

class OpenAiProvider
{
    private const PROVIDER = 'openai';

    private const DEFAULT_BASE_URL = 'https://api.openai.com/v1';

    private const DEFAULT_MODEL = 'gpt-5.4-nano';

    /**
     * Send a receipt extraction request to the OpenAI Responses API.
     *
     * Expected context keys:
     * - prompt: string
     * - input_type: images|pdf
     * - files: array<FileInput-like object with filename, mime, base64, data_uri properties>
     * - enabled_sections: array<string>
     * - excluded_sections: array<string>
     * - model: string|null
     * - is_repair: bool
     * - raw_text: string|null
     *
     * @param array<string, mixed> $context
     * @return array{text: string, provider: string, model: string, request_id: ?string, response_id: ?string, status: int|string|null}
     */
    public function extract(array $context): array
    {
        $apiKey = $this->apiKey();
        if ($apiKey === '') {
            throw new ProviderException('OpenAI API key is not configured.');
        }

        $model = $this->model($context);
        $payload = [
            'model' => $model,
            'input' => [
                [
                    'role' => 'user',
                    'content' => $this->contentParts($context),
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'receipt_result',
                    'schema' => $this->receiptJsonSchema($context),
                    'strict' => true,
                ],
            ],
        ];

        $response = $this->postWithRetries($apiKey, $payload);
        $body = $response->json();

        if (! is_array($body)) {
            throw new ProviderException('OpenAI returned an invalid JSON response.');
        }

        $text = $this->responseText($body);
        if ($text === '') {
            throw new ProviderException('OpenAI returned a response without output text.');
        }

        return [
            'text' => $text,
            'provider' => self::PROVIDER,
            'model' => $model,
            'request_id' => $this->headerValue($response, 'x-request-id'),
            'response_id' => isset($body['id']) && is_scalar($body['id']) ? (string) $body['id'] : null,
            'status' => isset($body['status']) && is_scalar($body['status']) ? (string) $body['status'] : $response->status(),
        ];
    }

    private function apiKey(): string
    {
        return trim((string) config('receiptscanner.providers.openai.api_key', ''));
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

        $configuredModel = trim((string) config('receiptscanner.model', ''));
        if ($configuredModel !== '') {
            return $configuredModel;
        }

        $providerDefault = trim((string) config('receiptscanner.providers.openai.default_model', self::DEFAULT_MODEL));

        return $providerDefault !== '' ? $providerDefault : self::DEFAULT_MODEL;
    }

    private function endpointUrl(): string
    {
        $baseUrl = trim((string) config('receiptscanner.providers.openai.base_url', self::DEFAULT_BASE_URL));
        if ($baseUrl === '') {
            $baseUrl = self::DEFAULT_BASE_URL;
        }

        return rtrim($baseUrl, '/') . '/responses';
    }

    /**
     * @param array<string, mixed> $context
     * @return array<int, array<string, mixed>>
     */
    private function contentParts(array $context): array
    {
        $prompt = isset($context['prompt']) && is_scalar($context['prompt']) ? (string) $context['prompt'] : '';
        $isRepair = (bool) ($context['is_repair'] ?? false);

        if ($isRepair) {
            $rawText = isset($context['raw_text']) && is_scalar($context['raw_text']) ? (string) $context['raw_text'] : '';
            if ($rawText !== '') {
                $prompt = trim($prompt) . "\n\nThe previous model output was not valid JSON. Repair it into valid JSON only, preserving the receipt facts and following the schema.\n\nPrevious output:\n" . $rawText;
            }

            return [
                [
                    'type' => 'input_text',
                    'text' => $prompt,
                ],
            ];
        }

        $parts = [
            [
                'type' => 'input_text',
                'text' => $prompt,
            ],
        ];

        $files = $context['files'] ?? [];
        if (! is_array($files) || $files === []) {
            throw new ProviderException('OpenAI extraction requires at least one normalized receipt file.');
        }

        foreach ($files as $file) {
            if (! is_object($file)) {
                throw new ProviderException('OpenAI extraction received an invalid normalized receipt file.');
            }

            $parts[] = $this->fileContentPart($file, isset($context['input_type']) && is_scalar($context['input_type']) ? (string) $context['input_type'] : '');
        }

        return $parts;
    }

    /**
     * @return array<string, mixed>
     */
    private function fileContentPart(object $file, string $inputType): array
    {
        $mime = strtolower(trim((string) $this->fileValue($file, 'mime')));
        $filename = $this->safeFilename((string) ($this->fileValue($file, 'filename') ?? 'receipt'));
        $dataUri = trim((string) ($this->fileValue($file, 'data_uri') ?? ''));

        if ($dataUri === '') {
            $base64 = trim((string) ($this->fileValue($file, 'base64') ?? ''));
            if ($base64 !== '' && $mime !== '') {
                $dataUri = 'data:' . $mime . ';base64,' . $base64;
            }
        }

        if ($dataUri === '') {
            throw new ProviderException('OpenAI extraction received a file without base64 data.');
        }

        if ($mime === 'application/pdf' || $inputType === 'pdf') {
            return [
                'type' => 'input_file',
                'filename' => $filename !== '' ? $filename : 'receipt.pdf',
                'file_data' => $dataUri,
            ];
        }

        if (str_starts_with($mime, 'image/')) {
            return [
                'type' => 'input_image',
                'image_url' => $dataUri,
            ];
        }

        throw new ProviderException('OpenAI extraction received an unsupported file MIME type.');
    }

    private function fileValue(object $file, string $property): mixed
    {
        if (property_exists($file, $property)) {
            return $file->{$property};
        }

        $camel = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $property))));
        if ($camel !== $property && property_exists($file, $camel)) {
            return $file->{$camel};
        }

        if (method_exists($file, 'toArray')) {
            $values = $file->toArray();
            if (is_array($values)) {
                if (array_key_exists($property, $values)) {
                    return $values[$property];
                }

                if ($camel !== $property && array_key_exists($camel, $values)) {
                    return $values[$camel];
                }
            }
        }

        return null;
    }

    private function safeFilename(string $filename): string
    {
        $filename = trim($filename);
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?: '';
        $filename = trim($filename, '._-');

        return $filename !== '' ? $filename : 'receipt';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function postWithRetries(string $apiKey, array $payload): Response
    {
        $attempts = max(1, (int) config('receiptscanner.retries.attempts', 2));
        $baseDelayMs = max(0, (int) config('receiptscanner.retries.base_delay_ms', 250));
        $timeout = max(1, (int) config('receiptscanner.timeout', 60));
        $endpoint = $this->endpointUrl();
        $lastConnectionException = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = Http::timeout($timeout)
                    ->acceptJson()
                    ->asJson()
                    ->withToken($apiKey)
                    ->post($endpoint, $payload);

                if ($response->successful()) {
                    return $response;
                }

                if ($this->isTransientStatus($response->status()) && $attempt < $attempts) {
                    $this->sleepBeforeRetry($baseDelayMs, $attempt);
                    continue;
                }

                throw $this->providerExceptionFromResponse($response);
            } catch (ConnectionException $exception) {
                $lastConnectionException = $exception;

                if ($attempt < $attempts) {
                    $this->sleepBeforeRetry($baseDelayMs, $attempt);
                    continue;
                }
            }
        }

        throw new ProviderException('OpenAI request failed due to a connection error.', 0, $lastConnectionException);
    }

    private function sleepBeforeRetry(int $baseDelayMs, int $attempt): void
    {
        if ($baseDelayMs <= 0) {
            return;
        }

        usleep($baseDelayMs * (2 ** max(0, $attempt - 1)) * 1000);
    }

    private function isTransientStatus(int $status): bool
    {
        return $status === 429 || $status >= 500;
    }

    private function providerExceptionFromResponse(Response $response): ProviderException
    {
        $status = $response->status();
        $requestId = $this->headerValue($response, 'x-request-id');
        $body = $response->json();
        $details = [];

        if (is_array($body) && isset($body['error']) && is_array($body['error'])) {
            foreach (['type', 'code', 'param', 'message'] as $key) {
                if (isset($body['error'][$key]) && is_scalar($body['error'][$key]) && (string) $body['error'][$key] !== '') {
                    $details[] = $key . '=' . $this->truncate((string) $body['error'][$key], 240);
                }
            }
        }

        $message = 'OpenAI request failed';
        $message .= ' status=' . $status;

        if ($requestId !== null && $requestId !== '') {
            $message .= ' request_id=' . $requestId;
        }

        if ($details !== []) {
            $message .= ' ' . implode(' ', $details);
        }

        return new ProviderException($message, $status);
    }

    private function headerValue(Response $response, string $header): ?string
    {
        $value = $response->header($header);

        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    private function truncate(string $value, int $limit): string
    {
        if (strlen($value) <= $limit) {
            return $value;
        }

        return substr($value, 0, max(0, $limit - 3)) . '...';
    }

    /**
     * @param array<string, mixed> $body
     */
    private function responseText(array $body): string
    {
        if (isset($body['output_text']) && is_scalar($body['output_text'])) {
            return trim((string) $body['output_text']);
        }

        $texts = [];
        $this->collectTextBlocks($body['output'] ?? null, $texts);

        return trim(implode("\n", array_filter($texts, static fn (string $text): bool => trim($text) !== '')));
    }

    /**
     * @param array<int, string> $texts
     */
    private function collectTextBlocks(mixed $value, array &$texts): void
    {
        if (! is_array($value)) {
            return;
        }

        if (isset($value['text']) && is_scalar($value['text'])) {
            $type = isset($value['type']) && is_scalar($value['type']) ? (string) $value['type'] : '';
            if ($type === '' || in_array($type, ['output_text', 'text'], true)) {
                $texts[] = (string) $value['text'];
            }
        }

        foreach ($value as $child) {
            if (is_array($child)) {
                $this->collectTextBlocks($child, $texts);
            }
        }
    }

    /**
     * Build a strict OpenAI Responses API JSON schema for the enabled receipt sections.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function receiptJsonSchema(array $context): array
    {
        $sections = $this->enabledSections($context);

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
     * @param array<string, mixed> $context
     * @return array<int, string>
     */
    private function enabledSections(array $context): array
    {
        $default = ['merchant', 'receipt', 'totals', 'vat_breakdown', 'line_items', 'mcc', 'confidence', 'warnings', 'metadata'];
        $enabled = $context['enabled_sections'] ?? $default;
        $excluded = $context['excluded_sections'] ?? [];

        if (! is_array($enabled) || $enabled === []) {
            $enabled = $default;
        }

        if (! is_array($excluded)) {
            $excluded = [];
        }

        $enabled = array_values(array_filter(array_map('strval', $enabled), static fn (string $section): bool => $section !== 'schema_version'));
        $excluded = array_values(array_map('strval', $excluded));

        return array_values(array_intersect($default, array_diff($enabled, $excluded)));
    }

    /**
     * @return array<string, mixed>
     */
    private function sectionSchema(string $section): array
    {
        return match ($section) {
            'merchant' => $this->nullableObject([
                'name' => $this->nullableString(),
                'address' => $this->nullableString(),
                'city' => $this->nullableString(),
                'region' => $this->nullableString(),
                'postal_code' => $this->nullableString(),
                'country' => $this->nullableString(),
                'tax_id' => $this->nullableString(),
                'phone' => $this->nullableString(),
                'email' => $this->nullableString(),
                'website' => $this->nullableString(),
            ]),
            'receipt' => $this->nullableObject([
                'date' => $this->nullableString(),
                'time' => $this->nullableString(),
                'datetime' => $this->nullableString(),
                'number' => $this->nullableString(),
                'currency' => $this->nullableString(),
                'payment_method' => $this->nullableString(),
                'card_last4' => $this->nullableString(),
                'locale' => $this->nullableString(),
            ]),
            'totals' => $this->nullableObject([
                'subtotal' => $this->nullableNumber(),
                'tax' => $this->nullableNumber(),
                'total' => $this->nullableNumber(),
                'tip' => $this->nullableNumber(),
                'discount' => $this->nullableNumber(),
                'fees' => $this->nullableNumber(),
                'shipping' => $this->nullableNumber(),
                'rounding' => $this->nullableNumber(),
                'cash_paid' => $this->nullableNumber(),
                'change' => $this->nullableNumber(),
                'currency' => $this->nullableString(),
            ]),
            'vat_breakdown' => [
                'type' => 'array',
                'items' => $this->object([
                    'rate' => $this->nullableNumber(),
                    'net' => $this->nullableNumber(),
                    'tax' => $this->nullableNumber(),
                    'gross' => $this->nullableNumber(),
                    'code' => $this->nullableString(),
                ]),
            ],
            'line_items' => [
                'type' => 'array',
                'items' => $this->object([
                    'description' => $this->nullableString(),
                    'quantity' => $this->nullableNumber(),
                    'unit_price' => $this->nullableNumber(),
                    'total' => $this->nullableNumber(),
                    'sku' => $this->nullableString(),
                    'category' => $this->nullableString(),
                    'vat_rate' => $this->nullableNumber(),
                ]),
            ],
            'mcc' => $this->nullableObject([
                'code' => $this->nullableString(),
                'description' => $this->nullableString(),
            ]),
            'confidence' => $this->nullableObject([
                'overall' => $this->nullableNumber(),
                'merchant' => $this->nullableNumber(),
                'receipt' => $this->nullableNumber(),
                'totals' => $this->nullableNumber(),
                'vat_breakdown' => $this->nullableNumber(),
                'line_items' => $this->nullableNumber(),
                'mcc' => $this->nullableNumber(),
            ]),
            'warnings' => [
                'type' => 'array',
                'items' => [
                    'type' => 'string',
                ],
            ],
            'metadata' => $this->nullableObject([
                'provider' => $this->nullableString(),
                'model' => $this->nullableString(),
                'input_type' => $this->nullableString(),
                'page_count' => $this->nullableInteger(),
                'language' => $this->nullableString(),
                'country' => $this->nullableString(),
                'currency' => $this->nullableString(),
            ]),
            default => $this->nullableObject([]),
        };
    }

    /**
     * @param array<string, mixed> $properties
     * @return array<string, mixed>
     */
    private function nullableObject(array $properties): array
    {
        $schema = $this->object($properties);
        $schema['type'] = ['object', 'null'];

        return $schema;
    }

    /**
     * @param array<string, mixed> $properties
     * @return array<string, mixed>
     */
    private function object(array $properties): array
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

    /**
     * @return array<string, mixed>
     */
    private function nullableInteger(): array
    {
        return ['type' => ['integer', 'null']];
    }
}

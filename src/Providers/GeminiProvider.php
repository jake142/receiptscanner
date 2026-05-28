<?php

declare(strict_types=1);

namespace Jake142\ReceiptScanner\Providers;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Jake142\ReceiptScanner\Prompt\ReceiptPrompt;
use RuntimeException;
use Throwable;

class GeminiProvider
{
    /**
     * Extract structured receipt data from image/PDF inputs using Gemini.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function extract(array $context): array
    {
        $apiKey = trim((string) config('receiptscanner.providers.gemini.api_key', ''));

        if ($apiKey === '') {
            throw new InvalidArgumentException('Gemini API key is not configured. Set GEMINI_API_KEY or receiptscanner.providers.gemini.api_key.');
        }

        $options = is_array($context['options'] ?? null) ? $context['options'] : [];
        $model = trim((string) ($options['model'] ?? config('receiptscanner.providers.gemini.model', 'gemini-2.5-pro')));

        if ($model === '') {
            $model = 'gemini-2.5-pro';
        }

        $fields = $this->resolveFields($context, $options);
        $endpoint = $this->buildEndpoint((string) config('receiptscanner.providers.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta'), $model);

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => $this->buildParts($context, $fields),
                ],
            ],
            'generationConfig' => [
                'temperature' => 0,
                'maxOutputTokens' => 8192,
                'responseMimeType' => 'application/json',
                'responseSchema' => $this->buildResponseSchema($fields),
            ],
        ];

        $response = $this->postJsonWithRetries(
            $endpoint,
            $apiKey,
            $payload,
            (int) ($options['timeout'] ?? config('receiptscanner.timeout', 60)),
            (int) ($options['retries'] ?? config('receiptscanner.retries', 2))
        );

        if ($response->failed()) {
            $diagnostic = $this->buildHttpErrorDiagnostic($response);
            $this->logSafeFailure($diagnostic);

            throw new RuntimeException('Gemini receipt extraction failed: '.json_encode($diagnostic, JSON_UNESCAPED_SLASHES));
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new RuntimeException('Gemini receipt extraction failed: upstream response was not valid JSON.');
        }

        $text = $this->extractFirstText($body);
        $decoded = $this->decodeJsonObject($text);

        if (! is_array($decoded)) {
            throw new RuntimeException('Gemini receipt extraction failed: model output was not a JSON object.');
        }

        return $this->normalizeReceiptPayload($decoded, $fields);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $options
     * @return array<int, string>
     */
    private function resolveFields(array $context, array $options): array
    {
        $fields = $context['fields'] ?? $options['fields'] ?? config('receiptscanner.enabled_fields', []);
        $canonical = (new ReceiptPrompt())->fields();

        if (is_string($fields)) {
            $fields = array_map('trim', explode(',', $fields));
        }

        if (! is_array($fields)) {
            $fields = [];
        }

        $requested = [];

        foreach ($fields as $key => $value) {
            if (is_int($key) && is_string($value)) {
                $field = strtolower(trim($value));
                if ($field !== '') {
                    $requested[$field] = true;
                }
                continue;
            }

            if (is_string($key) && is_bool($value)) {
                if ($value) {
                    $requested[strtolower(trim($key))] = true;
                }
                continue;
            }

            if (is_string($key) && is_scalar($value) && filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
                $requested[strtolower(trim($key))] = true;
            }
        }

        if ($requested === []) {
            return $canonical;
        }

        $resolved = [];

        foreach ($canonical as $field) {
            if (isset($requested[$field])) {
                $resolved[] = $field;
            }
        }

        return $resolved === [] ? $canonical : $resolved;
    }

    private function buildEndpoint(string $baseUrl, string $model): string
    {
        $baseUrl = rtrim($baseUrl, '/');
        $modelPath = str_starts_with($model, 'models/') ? $model : 'models/'.$model;
        $encodedModelPath = implode('/', array_map('rawurlencode', explode('/', $modelPath)));

        return $baseUrl.'/'.$encodedModelPath.':generateContent';
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<int, string>  $fields
     * @return array<int, array<string, mixed>>
     */
    private function buildParts(array $context, array $fields): array
    {
        $paths = $context['paths'] ?? $context['path'] ?? [];

        if (is_string($paths)) {
            $paths = [$paths];
        }

        if (! is_array($paths) || $paths === []) {
            throw new InvalidArgumentException('Gemini receipt extraction requires at least one image or PDF path.');
        }

        $parts = [
            [
                'text' => $this->buildPrompt($fields, count($paths)),
            ],
        ];

        foreach ($paths as $path) {
            $path = (string) $path;

            if ($path === '' || ! is_file($path) || ! is_readable($path)) {
                throw new InvalidArgumentException('Gemini receipt extraction input file is not readable: '.$path);
            }

            $contents = file_get_contents($path);

            if ($contents === false) {
                throw new InvalidArgumentException('Gemini receipt extraction input file could not be read: '.$path);
            }

            $parts[] = [
                'inlineData' => [
                    'mimeType' => $this->mimeTypeForPath($path, $context),
                    'data' => base64_encode($contents),
                ],
            ];
        }

        return $parts;
    }

    /**
     * @param  array<int, string>  $fields
     */
    private function buildPrompt(array $fields, int $inputCount): string
    {
        $suffix = implode("\n", [
            'Extract the receipt data from the attached image(s) or PDF.',
            'Analyze all provided inputs together as one receipt. If multiple images are provided, merge them in order from top to bottom and do not duplicate line items.',
            'Return only one valid JSON object. Do not wrap it in Markdown and do not include explanatory text.',
            'Use these top-level fields exactly: ' . json_encode($fields, JSON_UNESCAPED_SLASHES) . '.',
            'Input count: ' . $inputCount . '.',
        ]);
        $base = config('receiptscanner.prompt.extraction');
        $base = is_string($base) ? trim($base) : '';
        if ($base === '') {
            return trim($suffix);
        }
        return $base . "\n\n" . trim($suffix);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function mimeTypeForPath(string $path, array $context): string
    {
        $configured = $context['mime_type'] ?? null;

        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'heic' => 'image/heic',
            'heif' => 'image/heif',
            default => 'image/jpeg',
        };
    }

    /**
     * @param  array<int, string>  $fields
     * @return array<string, mixed>
     */
    private function buildResponseSchema(array $fields): array
    {
        $properties = [];

        foreach ($fields as $field) {
            $properties[$field] = $this->schemaForField($field);
        }

        return [
            'type' => 'OBJECT',
            'properties' => $properties,
            'required' => array_keys($properties),
            'propertyOrdering' => array_keys($properties),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function schemaForField(string $field): array
    {
        return match ($field) {
            'merchant', 'currency', 'date', 'mcc', 'purchase_country', 'purchase_city' => [
                'type' => 'STRING',
                'nullable' => true,
            ],
            'total_amount', 'vat_amount', 'confidence', 'tip' => [
                'type' => 'NUMBER',
                'nullable' => true,
            ],
            'line_items' => [
                'type' => 'ARRAY',
                'items' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'description' => ['type' => 'STRING', 'nullable' => true],
                        'quantity' => ['type' => 'NUMBER', 'nullable' => true],
                        'unit_price' => ['type' => 'NUMBER', 'nullable' => true],
                        'amount' => ['type' => 'NUMBER', 'nullable' => true],
                    ],
                ],
            ],
            'vats' => [
                'type' => 'ARRAY',
                'items' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'rate' => ['type' => 'NUMBER', 'nullable' => true],
                        'amount' => ['type' => 'NUMBER', 'nullable' => true],
                        'amount_inc_vat' => ['type' => 'NUMBER', 'nullable' => true],
                        'amount_ex_vat' => ['type' => 'NUMBER', 'nullable' => true],
                    ],
                ],
            ],
            default => [
                'type' => 'STRING',
                'nullable' => true,
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $fields
     * @return array<string, mixed>
     */
    private function normalizeReceiptPayload(array $payload, array $fields): array
    {
        $normalized = [
            'merchant' => $this->normalizeNullableString($payload['merchant'] ?? null),
            'total_amount' => $this->normalizeNullableNumber($payload['total_amount'] ?? $payload['amount'] ?? null),
            'currency' => $this->normalizeNullableString($payload['currency'] ?? null),
            'date' => $this->normalizeNullableString($payload['date'] ?? null),
            'vat_amount' => $this->normalizeNullableNumber($payload['vat_amount'] ?? $payload['tax_amount'] ?? $payload['vat'] ?? $payload['tax'] ?? null),
            'mcc' => $this->normalizeNullableString($payload['mcc'] ?? null),
            'vats' => $this->normalizeVats($payload['vats'] ?? null, $payload),
            'line_items' => $this->normalizeLineItems($payload['line_items'] ?? null),
            'confidence' => $this->normalizeNullableNumber($payload['confidence'] ?? null),
            'tip' => $this->normalizeNullableNumber($payload['tip'] ?? null),
            'purchase_country' => $this->normalizeNullableString($payload['purchase_country'] ?? null),
            'purchase_city' => $this->normalizeNullableString($payload['purchase_city'] ?? null),
        ];

        $result = [];

        foreach ($fields as $field) {
            if (array_key_exists($field, $normalized)) {
                $result[$field] = $normalized[$field];
            }
        }

        return $result;
    }

    /**
     * @param  mixed  $value
     * @return array<int, array<string, mixed>>
     */
    private function normalizeVats(mixed $value, array $payload = []): array
    {
        $rows = is_array($value) ? $value : [];

        if ($rows === []) {
            $legacyVat = $payload['vat'] ?? $payload['tax'] ?? null;
            if (is_array($legacyVat)) {
                $rows = $legacyVat;
            } elseif ($legacyVat !== null || isset($payload['vat_amount']) || isset($payload['tax_amount'])) {
                $rows = [[
                    'rate' => $payload['vat_rate'] ?? $payload['tax_rate'] ?? null,
                    'amount' => $payload['vat_amount'] ?? $payload['tax_amount'] ?? $legacyVat,
                    'amount_inc_vat' => $payload['amount_including_vat'] ?? $payload['total_amount'] ?? $payload['amount'] ?? null,
                    'amount_ex_vat' => $payload['amount_excluding_vat'] ?? $payload['subtotal'] ?? null,
                ]];
            }
        }

        $normalized = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $normalized[] = [
                'rate' => $this->normalizeNullableNumber($row['rate'] ?? $row['vat_rate'] ?? null),
                'amount' => $this->normalizeNullableNumber($row['amount'] ?? $row['vat_amount'] ?? $row['tax_amount'] ?? null),
                'amount_inc_vat' => $this->normalizeNullableNumber($row['amount_inc_vat'] ?? $row['amount_including_vat'] ?? $row['gross_amount'] ?? $payload['total_amount'] ?? $payload['amount'] ?? null),
                'amount_ex_vat' => $this->normalizeNullableNumber($row['amount_ex_vat'] ?? $row['amount_excluding_vat'] ?? $row['net_amount'] ?? null),
            ];
        }

        return $normalized;
    }

    /**
     * @param  mixed  $value
     */
    private function normalizeNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $value = trim($value);
            return $value === '' ? null : $value;
        }

        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        return null;
    }

    /**
     * @param  mixed  $value
     */
    private function normalizeNullableNumber(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            $normalized = str_replace([' ', ','], ['', '.'], trim($value));

            if ($normalized === '' || ! is_numeric($normalized)) {
                return null;
            }

            return (float) $normalized;
        }

        return null;
    }

    /**
     * @param  mixed  $value
     * @return array<int, array<string, mixed>>
     */
    private function normalizeLineItems(mixed $value): array
    {
        $items = is_array($value) ? $value : [];

        $normalized = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $normalized[] = [
                'description' => $this->normalizeNullableString($item['description'] ?? $item['name'] ?? null),
                'quantity' => $this->normalizeNullableNumber($item['quantity'] ?? null),
                'unit_price' => $this->normalizeNullableNumber($item['unit_price'] ?? $item['unitPrice'] ?? null),
                'amount' => $this->normalizeNullableNumber($item['amount'] ?? $item['total'] ?? null),
            ];
        }

        return $normalized;
    }

    private function postJsonWithRetries(string $endpoint, string $apiKey, array $payload, int $timeout, int $retries): Response
    {
        $attempts = max(1, $retries + 1);
        $lastThrowable = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = Http::acceptJson()
                    ->asJson()
                    ->timeout(max(1, $timeout))
                    ->withHeaders([
                        'x-goog-api-key' => $apiKey,
                    ])
                    ->post($endpoint, $payload);

                if (! $this->shouldRetryResponse($response) || $attempt === $attempts) {
                    return $response;
                }
            } catch (Throwable $throwable) {
                $lastThrowable = $throwable;

                if ($attempt === $attempts) {
                    throw new RuntimeException('Gemini receipt extraction failed: '.$throwable->getMessage(), 0, $throwable);
                }
            }

            usleep(250000 * $attempt);
        }

        throw new RuntimeException('Gemini receipt extraction failed: '.($lastThrowable?->getMessage() ?? 'request did not complete'));
    }

    private function shouldRetryResponse(Response $response): bool
    {
        return $response->status() === 429 || $response->serverError();
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function extractFirstText(array $body): string
    {
        $candidates = $body['candidates'] ?? [];

        if (is_array($candidates)) {
            foreach ($candidates as $candidate) {
                if (! is_array($candidate)) {
                    continue;
                }

                $parts = $candidate['content']['parts'] ?? [];

                if (! is_array($parts)) {
                    continue;
                }

                $texts = [];

                foreach ($parts as $part) {
                    if (is_array($part) && isset($part['text']) && trim((string) $part['text']) !== '') {
                        $texts[] = (string) $part['text'];
                    }
                }

                $text = trim(implode("\n", $texts));

                if ($text !== '') {
                    return $text;
                }
            }
        }

        if (isset($body['text']) && trim((string) $body['text']) !== '') {
            return trim((string) $body['text']);
        }

        throw new RuntimeException('Gemini receipt extraction failed: upstream response did not contain text output.');
    }

    /**
     * @return array<string, mixed>|array<int, mixed>
     */
    private function decodeJsonObject(string $text): array
    {
        $text = trim($text);

        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/is', $text, $matches) === 1) {
            $text = trim($matches[1]);
        }

        $decoded = json_decode($text, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        $fragment = $this->extractJsonFragment($text);

        if ($fragment !== null) {
            $decoded = json_decode($fragment, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        throw new RuntimeException('Gemini receipt extraction failed: model output was not valid JSON.');
    }

    private function extractJsonFragment(string $text): ?string
    {
        $starts = array_filter([
            strpos($text, '{'),
            strpos($text, '['),
        ], static fn (int|false $position): bool => $position !== false);

        if ($starts === []) {
            return null;
        }

        $start = min($starts);
        $opening = $text[$start];
        $closing = $opening === '{' ? '}' : ']';
        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($text);

        for ($index = $start; $index < $length; $index++) {
            $char = $text[$index];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }

                if ($char === '\\') {
                    $escaped = true;
                    continue;
                }

                if ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;
                continue;
            }

            if ($char === $opening) {
                $depth++;
            } elseif ($char === $closing) {
                $depth--;

                if ($depth === 0) {
                    return substr($text, $start, $index - $start + 1);
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildHttpErrorDiagnostic(Response $response): array
    {
        $body = $response->json();
        $message = null;

        if (is_array($body)) {
            $message = $body['error']['message'] ?? $body['message'] ?? null;
        }

        if (! is_string($message) || trim($message) === '') {
            $message = $response->body();
        }

        return [
            'provider' => 'gemini',
            'endpoint_mode' => 'generateContent',
            'status' => $response->status(),
            'request_id' => $response->header('x-request-id')
                ?? $response->header('x-goog-request-id')
                ?? $response->header('x-cloud-trace-context'),
            'message' => $this->safeExcerpt((string) $message),
        ];
    }

    /**
     * @param  array<string, mixed>  $diagnostic
     */
    private function logSafeFailure(array $diagnostic): void
    {
        if (! $this->loggingEnabled()) {
            return;
        }

        Log::channel((string) config('logging.default', 'stack'))->warning('Gemini receipt extraction failed.', $diagnostic);
    }

    private function loggingEnabled(): bool
    {
        $value = config('receiptscanner.logging', false);

        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function safeExcerpt(string $message): string
    {
        $message = preg_replace('/[A-Za-z0-9+\/]{160,}={0,2}/', '[redacted-base64]', $message) ?? $message;
        $message = preg_replace('/(x-goog-api-key|api-key|authorization)\s*[:=]\s*[^\s,}]+/i', '$1: [redacted]', $message) ?? $message;

        return mb_substr(trim($message), 0, 1000);
    }
}

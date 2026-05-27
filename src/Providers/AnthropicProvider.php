<?php

declare(strict_types=1);

namespace Jake142\ReceiptScanner\Providers;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class AnthropicProvider
{
    private const DEFAULT_BASE_URL = 'https://api.anthropic.com/v1';
    private const DEFAULT_MODEL = 'claude-sonnet-4-20250514';
    private const ANTHROPIC_VERSION = '2023-06-01';
    private const PDF_BETA = 'pdfs-2024-09-25';

    /**
     * Extract structured receipt data from image or PDF inputs using Anthropic Messages API.
     */
    public function extract(array $context): array
    {
        $apiKey = trim((string) config('receiptscanner.providers.anthropic.api_key', ''));

        if ($apiKey === '') {
            throw new RuntimeException('Anthropic API key is not configured. Set ANTHROPIC_API_KEY or receiptscanner.providers.anthropic.api_key.');
        }

        $options = is_array($context['options'] ?? null) ? $context['options'] : [];
        $model = trim((string) ($options['model'] ?? config('receiptscanner.providers.anthropic.model', self::DEFAULT_MODEL)));

        if ($model === '') {
            $model = self::DEFAULT_MODEL;
        }

        $timeout = max(1, (int) ($options['timeout'] ?? config('receiptscanner.timeout', 60)));
        $retries = max(0, (int) ($options['retries'] ?? config('receiptscanner.retries', 2)));
        $fields = $this->resolveFields($context, $options);
        $usesPdf = false;
        $payload = $this->buildPayload($context, $model, $fields, $usesPdf);

        $headers = [
            'x-api-key' => $apiKey,
            'anthropic-version' => self::ANTHROPIC_VERSION,
        ];

        if ($usesPdf) {
            $headers['anthropic-beta'] = self::PDF_BETA;
        }

        try {
            $request = Http::withHeaders($headers)
                ->acceptJson()
                ->asJson()
                ->timeout($timeout);

            if ($retries > 0) {
                $request = $request->retry($retries, 500);
            }

            $response = $request->post($this->messagesEndpoint(), $payload);
        } catch (Throwable $exception) {
            $this->logDiagnostic('Anthropic receipt extraction transport error', [
                'provider' => 'anthropic',
                'endpoint_mode' => null,
                'status' => null,
                'request_id' => null,
                'message' => $this->safeExcerpt($exception->getMessage()),
            ]);

            throw new RuntimeException('Anthropic receipt extraction request failed: '.$exception->getMessage(), 0, $exception);
        }

        if ($response->failed()) {
            $this->throwProviderHttpError($response);
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new RuntimeException('Anthropic receipt extraction returned a non-JSON response.');
        }

        $toolInput = $this->extractToolInput($body);

        if ($toolInput !== null) {
            return $toolInput;
        }

        $text = $this->extractText($body);

        if ($text === null) {
            throw new RuntimeException('Anthropic receipt extraction response did not contain tool input or text output.');
        }

        return $this->decodeJsonObject($text);
    }

    private function messagesEndpoint(): string
    {
        $baseUrl = trim((string) config('receiptscanner.providers.anthropic.base_url', self::DEFAULT_BASE_URL));

        if ($baseUrl === '') {
            $baseUrl = self::DEFAULT_BASE_URL;
        }

        return rtrim($baseUrl, '/').'/messages';
    }

    private function buildPayload(array $context, string $model, array $fields, bool &$usesPdf): array
    {
        $content = [];

        foreach ($this->paths($context) as $path) {
            $block = $this->fileBlock($path, $context);

            if (($block['type'] ?? null) === 'document') {
                $usesPdf = true;
            }

            $content[] = $block;
        }

        $content[] = [
            'type' => 'text',
            'text' => $this->userPrompt($fields),
        ];

        return [
            'model' => $model,
            'max_tokens' => 4096,
            'system' => $this->systemPrompt(),
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $content,
                ],
            ],
            'tools' => [
                [
                    'name' => 'extract_receipt_json',
                    'description' => 'Return the structured JSON fields extracted from the supplied receipt image or PDF.',
                    'input_schema' => $this->toolInputSchema($fields),
                ],
            ],
            'tool_choice' => [
                'type' => 'tool',
                'name' => 'extract_receipt_json',
            ],
        ];
    }

    private function systemPrompt(): string
    {
        return 'You are a precise receipt extraction engine. Extract only information present in the supplied receipt image or PDF. Use null when a requested scalar value is unknown, use an empty array when no line items are visible, and do not invent merchants, dates, currencies, totals, tax values, VAT values, MCCs, confidence scores, or metadata.';
    }

    private function userPrompt(array $fields): string
    {
        return 'Extract the receipt into structured JSON using the extract_receipt_json tool. Return values for these package fields exactly when requested: '.implode(', ', $fields).'. Preserve the package schema: merchant, date, total, amount, tax, vat, currency, line_items, mcc, confidence, and metadata. Dates should be ISO-8601 when the receipt provides enough information. Currency should be an ISO-4217 code when it can be inferred from the receipt. Line items should be an array of objects.';
    }

    private function toolInputSchema(array $fields): array
    {
        $properties = [];

        foreach ($fields as $field) {
            $properties[$field] = $this->schemaForField($field);
        }

        return [
            'type' => 'object',
            'additionalProperties' => true,
            'properties' => $properties,
            'required' => array_values($fields),
        ];
    }

    private function schemaForField(string $field): array
    {
        return match ($field) {
            'merchant' => [
                'type' => ['string', 'null'],
                'description' => 'Merchant or store name printed on the receipt.',
            ],
            'date' => [
                'type' => ['string', 'null'],
                'description' => 'Receipt date, preferably ISO-8601 when the full date can be determined.',
            ],
            'total', 'amount', 'tax', 'vat' => [
                'type' => ['number', 'string', 'null'],
                'description' => 'Monetary amount from the receipt without inventing missing values.',
            ],
            'currency' => [
                'type' => ['string', 'null'],
                'description' => 'ISO-4217 currency code when it can be inferred.',
            ],
            'line_items' => [
                'type' => 'array',
                'description' => 'Purchased items visible on the receipt.',
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => true,
                    'properties' => [
                        'description' => ['type' => ['string', 'null']],
                        'quantity' => ['type' => ['number', 'string', 'null']],
                        'unit_price' => ['type' => ['number', 'string', 'null']],
                        'total' => ['type' => ['number', 'string', 'null']],
                        'amount' => ['type' => ['number', 'string', 'null']],
                        'tax' => ['type' => ['number', 'string', 'null']],
                        'sku' => ['type' => ['string', 'null']],
                    ],
                ],
            ],
            'mcc' => [
                'type' => ['string', 'null'],
                'description' => 'Merchant category code when present or confidently inferable from receipt evidence.',
            ],
            'confidence' => [
                'type' => ['number', 'null'],
                'minimum' => 0,
                'maximum' => 1,
                'description' => 'Overall extraction confidence between 0 and 1.',
            ],
            'metadata' => [
                'type' => ['object', 'null'],
                'additionalProperties' => true,
                'description' => 'Additional non-sensitive receipt metadata such as receipt number, payment method, store address, or locale when visible.',
            ],
            default => [
                'type' => ['string', 'number', 'boolean', 'array', 'object', 'null'],
                'description' => 'Configured receipt extraction field.',
            ],
        };
    }

    private function fileBlock(string $path, array $context): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Receipt input file is not readable: {$path}");
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Unable to read receipt input file: {$path}");
        }

        $mimeType = $this->mimeType($path, $context);
        $base64 = base64_encode($contents);

        if ($mimeType === 'application/pdf') {
            return [
                'type' => 'document',
                'source' => [
                    'type' => 'base64',
                    'media_type' => 'application/pdf',
                    'data' => $base64,
                ],
            ];
        }

        if (str_starts_with($mimeType, 'image/')) {
            $mimeType = $mimeType === 'image/jpg' ? 'image/jpeg' : $mimeType;

            if (! in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
                throw new RuntimeException("Anthropic receipt extraction does not support image MIME type {$mimeType}.");
            }

            return [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $mimeType,
                    'data' => $base64,
                ],
            ];
        }

        throw new RuntimeException("Anthropic receipt extraction supports receipt images and PDFs only; detected {$mimeType}.");
    }

    private function paths(array $context): array
    {
        $paths = $context['paths'] ?? ($context['path'] ?? null);

        if (is_string($paths)) {
            $paths = [$paths];
        }

        if (! is_array($paths)) {
            throw new RuntimeException('Receipt scan context must include one or more input paths.');
        }

        $paths = array_values(array_filter($paths, static fn ($path): bool => is_string($path) && trim($path) !== ''));

        if ($paths === []) {
            throw new RuntimeException('Receipt scan context must include one or more input paths.');
        }

        return $paths;
    }

    private function mimeType(string $path, array $context): string
    {
        $options = is_array($context['options'] ?? null) ? $context['options'] : [];
        $explicit = $options['mime_type'] ?? ($context['mime_type'] ?? null);

        if (is_string($explicit) && trim($explicit) !== '') {
            return $this->normalizeMimeType($explicit);
        }

        $detected = null;

        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);

            if ($finfo !== false) {
                $value = finfo_file($finfo, $path);
                finfo_close($finfo);

                if (is_string($value) && $value !== '') {
                    $detected = $value;
                }
            }
        }

        if ($detected === null) {
            $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
            $detected = match ($extension) {
                'pdf' => 'application/pdf',
                'jpg', 'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
                default => 'application/octet-stream',
            };
        }

        return $this->normalizeMimeType($detected);
    }

    private function normalizeMimeType(string $mimeType): string
    {
        $mimeType = strtolower(trim(explode(';', $mimeType, 2)[0]));

        return match ($mimeType) {
            'image/jpg' => 'image/jpeg',
            'application/x-pdf' => 'application/pdf',
            default => $mimeType,
        };
    }

    private function resolveFields(array $context, array $options): array
    {
        $fields = $options['fields'] ?? ($context['fields'] ?? config('receiptscanner.fields', []));

        if (! is_array($fields)) {
            $fields = [];
        }

        $fields = array_values(array_unique(array_filter(array_map(
            static fn ($field): string => is_string($field) ? trim($field) : '',
            $fields
        ))));

        if ($fields !== []) {
            return $fields;
        }

        return [
            'merchant',
            'date',
            'total',
            'amount',
            'tax',
            'vat',
            'currency',
            'line_items',
            'mcc',
            'confidence',
            'metadata',
        ];
    }

    private function extractToolInput(array $body): ?array
    {
        $content = $body['content'] ?? null;

        if (! is_array($content)) {
            return null;
        }

        foreach ($content as $block) {
            if (! is_array($block)) {
                continue;
            }

            if (($block['type'] ?? null) === 'tool_use' && ($block['name'] ?? null) === 'extract_receipt_json' && is_array($block['input'] ?? null)) {
                return $block['input'];
            }
        }

        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'tool_use' && is_array($block['input'] ?? null)) {
                return $block['input'];
            }
        }

        return null;
    }

    private function extractText(array $body): ?string
    {
        $content = $body['content'] ?? null;

        if (is_string($content) && trim($content) !== '') {
            return $content;
        }

        if (! is_array($content)) {
            return null;
        }

        foreach ($content as $block) {
            if (is_array($block) && is_string($block['text'] ?? null) && trim($block['text']) !== '') {
                return $block['text'];
            }
        }

        return null;
    }

    private function decodeJsonObject(string $text): array
    {
        $candidate = trim($text);
        $decoded = json_decode($candidate, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/```(?:json)?\s*(.*?)\s*```/is', $candidate, $matches) === 1) {
            $decoded = json_decode(trim($matches[1]), true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $start = strpos($candidate, '{');
        $end = strrpos($candidate, '}');

        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($candidate, $start, $end - $start + 1), true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        throw new RuntimeException('Anthropic receipt extraction response did not contain a valid JSON object.');
    }

    private function throwProviderHttpError(Response $response): never
    {
        $requestId = $this->requestId($response);
        $message = $this->providerErrorMessage($response);
        $diagnostic = [
            'provider' => 'anthropic',
            'endpoint_mode' => null,
            'status' => $response->status(),
            'request_id' => $requestId,
            'message' => $message,
        ];

        $this->logDiagnostic('Anthropic receipt extraction HTTP error', $diagnostic);

        throw new RuntimeException('Anthropic receipt extraction failed with HTTP '.$response->status().($requestId ? " (request_id {$requestId})" : '').': '.$message);
    }

    private function providerErrorMessage(Response $response): string
    {
        $json = $response->json();

        if (is_array($json)) {
            $error = $json['error'] ?? null;

            if (is_array($error)) {
                $type = is_string($error['type'] ?? null) ? $error['type'] : null;
                $message = is_string($error['message'] ?? null) ? $error['message'] : null;

                if ($type !== null && $message !== null) {
                    return $this->safeExcerpt($type.': '.$message);
                }

                if ($message !== null) {
                    return $this->safeExcerpt($message);
                }
            }

            if (is_string($json['message'] ?? null)) {
                return $this->safeExcerpt($json['message']);
            }
        }

        return $this->safeExcerpt($response->body());
    }

    private function requestId(Response $response): ?string
    {
        foreach (['request-id', 'x-request-id', 'anthropic-request-id'] as $header) {
            $value = $response->header($header);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function safeExcerpt(string $value, int $limit = 500): string
    {
        $value = preg_replace('/Bearer\s+[A-Za-z0-9._\-]+/i', 'Bearer [redacted]', $value) ?? $value;
        $value = preg_replace('/(?:x-api-key|api-key|authorization)(\s*[:=]\s*)[^\s,}]+/i', '$1[redacted]', $value) ?? $value;
        $value = preg_replace('/[A-Za-z0-9+\/]{120,}={0,2}/', '[redacted-base64]', $value) ?? $value;
        $value = trim($value);

        if ($value === '') {
            return 'No response message provided by Anthropic.';
        }

        if (strlen($value) <= $limit) {
            return $value;
        }

        return substr($value, 0, $limit).'...';
    }

    private function logDiagnostic(string $message, array $diagnostic): void
    {
        if (! (bool) config('receiptscanner.logging', false)) {
            return;
        }

        Log::channel((string) config('logging.default', 'stack'))->warning($message, $diagnostic);
    }
}

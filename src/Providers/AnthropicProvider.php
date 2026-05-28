<?php

declare(strict_types=1);

namespace Jake142\ReceiptScanner\Providers;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Jake142\ReceiptScanner\Exceptions\ReceiptScannerException;
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
            throw new ReceiptScannerException('Anthropic API key is not configured. Set ANTHROPIC_API_KEY or receiptscanner.providers.anthropic.api_key.');
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

        $start = microtime(true);

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
                'model' => $model,
                'mime_type' => $context['mime_type'] ?? null,
                'retry_count' => $retries,
                'duration_ms' => (int) ((microtime(true) - $start) * 1000),
                'failure_category' => 'transport',
            ]);

            throw new ReceiptScannerException('Anthropic receipt extraction request failed: ' . $exception->getMessage(), 0, $exception);
        }

        if ($response->failed()) {
            $this->throwProviderHttpError($response, $model, $context['mime_type'] ?? null, $retries, $start);
        }

        $body = $response->json();

        if (!is_array($body)) {
            throw new ReceiptScannerException('Anthropic receipt extraction returned a non-JSON response.');
        }

        $toolInput = $this->extractToolInput($body);

        if ($toolInput !== null) {
            return $this->normalizeReceipt($toolInput, $fields);
        }

        $text = $this->extractText($body);

        if ($text === null) {
            throw new ReceiptScannerException('Anthropic receipt extraction response did not contain tool input or text output.');
        }

        return $this->normalizeReceipt($this->decodeJsonObject($text), $fields);
    }

    private function messagesEndpoint(): string
    {
        $baseUrl = trim((string) config('receiptscanner.providers.anthropic.base_url', self::DEFAULT_BASE_URL));

        if ($baseUrl === '') {
            $baseUrl = self::DEFAULT_BASE_URL;
        }

        return rtrim($baseUrl, '/') . '/messages';
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
        $prompt = config('receiptscanner.prompt.extraction');
        $prompt = is_string($prompt) ? trim($prompt) : '';
        if ($prompt !== '') {
            return $prompt;
        }

        return 'You are a receipt extraction engine. Return structured JSON via the extract_receipt_json tool.';
    }

    private function userPrompt(array $fields): string
    {
        return 'Extract the receipt into structured JSON using the extract_receipt_json tool. '
            . 'Analyze all provided images as one combined receipt. '
            . 'Return values for these package fields exactly when requested: ' . implode(', ', $fields) . '.';
    }

    private function toolInputSchema(array $fields): array
    {
        $properties = [];

        foreach ($fields as $field) {
            $properties[$field] = $this->schemaForField($field);
        }

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => $properties,
            'required' => array_values($fields),
        ];
    }

    private function schemaForField(string $field): array
    {
        return match ($field) {
            'merchant' => ['type' => ['string', 'null']],
            'total_amount' => ['type' => ['number', 'string', 'null']],
            'currency' => ['type' => ['string', 'null']],
            'date' => ['type' => ['string', 'null']],
            'vat_amount' => ['type' => ['number', 'string', 'null']],
            'mcc' => ['type' => ['string', 'null']],
            'confidence' => ['type' => ['number', 'null']],
            'tip' => ['type' => ['number', 'string', 'null']],
            'purchase_country' => ['type' => ['string', 'null']],
            'purchase_city' => ['type' => ['string', 'null']],
            'line_items' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'description' => ['type' => ['string', 'null']],
                        'quantity' => ['type' => ['number', 'string', 'null']],
                        'unit_price' => ['type' => ['number', 'string', 'null']],
                        'amount' => ['type' => ['number', 'string', 'null']],
                    ],
                    'required' => ['description', 'quantity', 'unit_price', 'amount'],
                ],
            ],
            'vats' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'rate' => ['type' => ['number', 'string', 'null']],
                        'amount' => ['type' => ['number', 'string', 'null']],
                        'amount_inc_vat' => ['type' => ['number', 'string', 'null']],
                        'amount_ex_vat' => ['type' => ['number', 'string', 'null']],
                    ],
                    'required' => ['rate', 'amount', 'amount_inc_vat', 'amount_ex_vat'],
                ],
            ],
            default => ['type' => ['string', 'number', 'boolean', 'array', 'object', 'null']],
        };
    }

    private function fileBlock(string $path, array $context): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new ReceiptScannerException("Receipt input file is not readable: {$path}");
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new ReceiptScannerException("Unable to read receipt input file: {$path}");
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

            if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
                throw new ReceiptScannerException("Anthropic receipt extraction does not support image MIME type {$mimeType}.");
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

        throw new ReceiptScannerException("Anthropic receipt extraction supports receipt images and PDFs only; detected {$mimeType}.");
    }

    private function paths(array $context): array
    {
        $paths = $context['paths'] ?? ($context['path'] ?? null);

        if (is_string($paths)) {
            $paths = [$paths];
        }

        if (!is_array($paths)) {
            throw new ReceiptScannerException('Receipt scan context must include one or more input paths.');
        }

        $paths = array_values(array_filter($paths, static fn($path): bool => is_string($path) && trim($path) !== ''));

        if ($paths === []) {
            throw new ReceiptScannerException('Receipt scan context must include one or more input paths.');
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

        if (!is_array($fields)) {
            $fields = [];
        }

        $fields = array_values(array_unique(array_filter(array_map(
            static fn($field): string => is_string($field) ? trim($field) : '',
            $fields
        ))));

        if ($fields !== []) {
            return $fields;
        }

        return [
            'merchant',
            'total_amount',
            'currency',
            'date',
            'vat_amount',
            'mcc',
            'vats',
            'line_items',
            'confidence',
            'tip',
            'purchase_country',
            'purchase_city',
        ];
    }

    private function extractToolInput(array $body): ?array
    {
        $content = $body['content'] ?? null;

        if (!is_array($content)) {
            return null;
        }

        foreach ($content as $block) {
            if (!is_array($block)) {
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

        if (!is_array($content)) {
            return null;
        }

        foreach ($content as $block) {
            if (is_array($block) && is_string($block['text'] ?? null) && trim((string) $block['text']) !== '') {
                return (string) $block['text'];
            }
        }

        return null;
    }

    private function decodeJsonObject(string $text): array
    {
        $decoded = json_decode($text, true);

        if (!is_array($decoded)) {
            throw new ReceiptScannerException('Anthropic receipt extraction returned invalid JSON.');
        }

        return $decoded;
    }

    private function normalizeReceipt(array $data, array $fields): array
    {
        $normalized = [];

        foreach ($fields as $field) {
            $normalized[$field] = array_key_exists($field, $data) ? $data[$field] : null;
        }

        return $normalized;
    }

    private function throwProviderHttpError(Response $response, string $model, ?string $mimeType, int $retries, float $start): void
    {
        $durationMs = (int) ((microtime(true) - $start) * 1000);
        $status = $response->status();
        $responseBody = null;

        try {
            $responseBody = $response->json();
        } catch (Throwable) {
            $responseBody = $response->body();
        }

        $this->logDiagnostic('Anthropic receipt extraction HTTP error', [
            'provider' => 'anthropic',
            'model' => $model,
            'mime_type' => $mimeType,
            'retry_count' => $retries,
            'duration_ms' => $durationMs,
            'http_status' => $status,
            'response' => $responseBody,
        ]);

        throw new ReceiptScannerException('Anthropic receipt extraction request failed with HTTP status ' . $status . '.');
    }

    private function logDiagnostic(string $message, array $context = []): void
    {
        $enabled = (bool) config('receiptscanner.logging.enabled', false);

        if (! $enabled) {
            return;
        }

        $channel = config('receiptscanner.logging.channel');
        $level = config('receiptscanner.logging.level', 'info');

        $context = array_merge(['provider' => 'anthropic'], $context);

        if (is_string($channel) && $channel !== '') {
            Log::channel($channel)->{$level}($message, $context);
            return;
        }

        Log::{$level}($message, $context);
    }
}

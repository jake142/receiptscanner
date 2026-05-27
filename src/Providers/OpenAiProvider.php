<?php

namespace Jake142\ReceiptScanner\Providers;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class OpenAiProvider
{
    /**
     * Extract structured receipt data from receipt image/PDF context using the OpenAI Responses API.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function extract(array $context): array
    {
        $apiKey = (string) config('receiptscanner.providers.openai.api_key', '');

        if ($apiKey === '') {
            throw new RuntimeException('OpenAI API key is not configured. Set OPENAI_API_KEY or receiptscanner.providers.openai.api_key.');
        }

        $options = is_array($context['options'] ?? null) ? $context['options'] : [];
        $fields = $this->fieldsFromContext($context);
        $model = (string) ($options['model'] ?? config('receiptscanner.providers.openai.model', 'gpt-4.1-mini'));
        $timeout = (int) ($options['timeout'] ?? config('receiptscanner.timeout', 60));
        $retries = (int) ($options['retries'] ?? config('receiptscanner.retries', 2));
        $baseUrl = rtrim((string) config('receiptscanner.providers.openai.base_url', 'https://api.openai.com/v1'), '/');
        $endpoint = $baseUrl . '/responses';

        $payload = [
            'model' => $model,
            'input' => [
                [
                    'role' => 'user',
                    'content' => $this->buildInputContent($context, $fields),
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'receipt_scan_result',
                    'strict' => true,
                    'schema' => $this->buildReceiptJsonSchema($fields),
                ],
            ],
        ];

        $response = $this->postJsonWithRetries($endpoint, $apiKey, $payload, $timeout, $retries);

        if (! $response->successful()) {
            $this->logHttpFailure($response);

            $message = $this->safeResponseMessage($response);
            throw new RuntimeException(sprintf(
                'OpenAI receipt extraction failed with HTTP %d%s',
                $response->status(),
                $message !== '' ? ': ' . $message : ''
            ));
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new RuntimeException('OpenAI receipt extraction returned a non-JSON response.');
        }

        $decoded = $this->decodeStructuredOutput($json);

        if (! is_array($decoded)) {
            throw new RuntimeException('OpenAI receipt extraction did not return a JSON object.');
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<int, string>
     */
    private function fieldsFromContext(array $context): array
    {
        $fields = $context['fields'] ?? null;

        if (! is_array($fields) || $fields === []) {
            $fields = config('receiptscanner.fields', []);
        }

        if (! is_array($fields) || $fields === []) {
            $fields = [
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

        return array_values(array_unique(array_filter(array_map(
            static fn ($field): string => is_string($field) ? trim($field) : '',
            $fields
        ))));
    }

    /**
     * @param array<string, mixed> $context
     * @param array<int, string> $fields
     * @return array<int, array<string, mixed>>
     */
    private function buildInputContent(array $context, array $fields): array
    {
        $content = [
            [
                'type' => 'input_text',
                'text' => $this->buildPrompt($fields),
            ],
        ];

        foreach ($this->pathsFromContext($context) as $path) {
            $mimeType = $this->mimeTypeForPath($path, $context);
            $dataUri = $this->dataUriForPath($path, $mimeType);

            if ($this->isPdfMimeType($mimeType)) {
                $content[] = [
                    'type' => 'input_file',
                    'filename' => basename($path) ?: 'receipt.pdf',
                    'file_data' => $dataUri,
                ];

                continue;
            }

            $content[] = [
                'type' => 'input_image',
                'image_url' => $dataUri,
            ];
        }

        return $content;
    }

    /**
     * @param array<int, string> $fields
     */
    private function buildPrompt(array $fields): string
    {
        return 'Extract the receipt information from the attached receipt image(s) or PDF. '
            . 'Return a single JSON object only, matching the provided JSON schema exactly. '
            . 'Use null when a requested field is not visible or cannot be determined. '
            . 'Do not invent values. Requested fields: ' . implode(', ', $fields) . '.';
    }

    /**
     * @param array<string, mixed> $context
     * @return array<int, string>
     */
    private function pathsFromContext(array $context): array
    {
        $paths = $context['paths'] ?? null;

        if (is_string($paths)) {
            $paths = [$paths];
        }

        if (! is_array($paths) || $paths === []) {
            throw new RuntimeException('No receipt image or PDF path was provided to the OpenAI provider.');
        }

        $normalized = [];

        foreach ($paths as $path) {
            if (! is_string($path) || trim($path) === '') {
                throw new RuntimeException('Receipt paths must be non-empty strings.');
            }

            $normalized[] = $path;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function mimeTypeForPath(string $path, array $context): string
    {
        $options = is_array($context['options'] ?? null) ? $context['options'] : [];
        $configuredMimeType = $options['mime_type'] ?? $context['mime_type'] ?? null;

        if (is_string($configuredMimeType) && trim($configuredMimeType) !== '') {
            return strtolower(trim($configuredMimeType));
        }

        if (is_file($path)) {
            $detected = @mime_content_type($path);

            if (is_string($detected) && $detected !== '') {
                return strtolower($detected);
            }
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'application/octet-stream',
        };
    }

    private function dataUriForPath(string $path, string $mimeType): string
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException(sprintf('Receipt file is not readable: %s', $path));
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(sprintf('Unable to read receipt file: %s', $path));
        }

        return sprintf('data:%s;base64,%s', $mimeType, base64_encode($contents));
    }

    private function isPdfMimeType(string $mimeType): bool
    {
        return strtolower($mimeType) === 'application/pdf';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function postJsonWithRetries(string $endpoint, string $apiKey, array $payload, int $timeout, int $retries): Response
    {
        $attempts = max(1, $retries + 1);
        $lastException = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = Http::timeout(max(1, $timeout))
                    ->acceptJson()
                    ->asJson()
                    ->withToken($apiKey)
                    ->post($endpoint, $payload);

                if (! $this->shouldRetryResponse($response) || $attempt === $attempts) {
                    return $response;
                }
            } catch (Throwable $exception) {
                $lastException = $exception;

                if ($attempt === $attempts) {
                    break;
                }
            }

            usleep(250000 * $attempt);
        }

        throw new RuntimeException(
            'OpenAI receipt extraction request failed before receiving a response.',
            0,
            $lastException
        );
    }

    private function shouldRetryResponse(Response $response): bool
    {
        $status = $response->status();

        return $status === 429 || ($status >= 500 && $status <= 599);
    }

    /**
     * @param array<int, string> $fields
     * @return array<string, mixed>
     */
    private function buildReceiptJsonSchema(array $fields): array
    {
        $knownProperties = [
            'merchant' => ['type' => ['string', 'null']],
            'date' => ['type' => ['string', 'null']],
            'total' => ['type' => ['number', 'string', 'null']],
            'amount' => ['type' => ['number', 'string', 'null']],
            'tax' => ['type' => ['number', 'string', 'null']],
            'vat' => ['type' => ['number', 'string', 'null']],
            'currency' => ['type' => ['string', 'null']],
            'line_items' => [
                'type' => ['array', 'null'],
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'name' => ['type' => ['string', 'null']],
                        'quantity' => ['type' => ['number', 'string', 'null']],
                        'unit_price' => ['type' => ['number', 'string', 'null']],
                        'total' => ['type' => ['number', 'string', 'null']],
                        'category' => ['type' => ['string', 'null']],
                    ],
                    'required' => ['name', 'quantity', 'unit_price', 'total', 'category'],
                ],
            ],
            'mcc' => ['type' => ['string', 'null']],
            'confidence' => ['type' => ['number', 'null'], 'minimum' => 0, 'maximum' => 1],
            'metadata' => [
                'type' => ['object', 'null'],
                'additionalProperties' => [
                    'type' => ['string', 'number', 'integer', 'boolean', 'null'],
                ],
            ],
        ];

        $properties = [];

        foreach ($fields as $field) {
            $properties[$field] = $knownProperties[$field] ?? [
                'type' => ['string', 'number', 'integer', 'boolean', 'object', 'array', 'null'],
            ];
        }

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => $properties,
            'required' => array_values($fields),
        ];
    }

    /**
     * @param array<string, mixed> $responseJson
     * @return array<string, mixed>|null
     */
    private function decodeStructuredOutput(array $responseJson): ?array
    {
        $directText = $this->firstTextOutput($responseJson);

        if ($directText === null) {
            return null;
        }

        $decoded = json_decode($this->stripJsonCodeFence($directText), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $responseJson
     */
    private function firstTextOutput(array $responseJson): ?string
    {
        $outputText = $responseJson['output_text'] ?? null;

        if (is_string($outputText) && trim($outputText) !== '') {
            return $outputText;
        }

        $output = $responseJson['output'] ?? null;

        if (is_array($output)) {
            foreach ($output as $outputItem) {
                if (! is_array($outputItem)) {
                    continue;
                }

                $content = $outputItem['content'] ?? null;

                if (! is_array($content)) {
                    continue;
                }

                foreach ($content as $contentItem) {
                    if (! is_array($contentItem)) {
                        continue;
                    }

                    $text = $contentItem['text'] ?? null;

                    if (is_string($text) && trim($text) !== '') {
                        return $text;
                    }

                    $json = $contentItem['json'] ?? null;

                    if (is_array($json)) {
                        return json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: null;
                    }
                }
            }
        }

        $choices = $responseJson['choices'] ?? null;

        if (is_array($choices)) {
            foreach ($choices as $choice) {
                if (! is_array($choice)) {
                    continue;
                }

                $message = $choice['message'] ?? null;
                $content = is_array($message) ? ($message['content'] ?? null) : null;

                if (is_string($content) && trim($content) !== '') {
                    return $content;
                }
            }
        }

        return null;
    }

    private function stripJsonCodeFence(string $text): string
    {
        $trimmed = trim($text);

        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/is', $trimmed, $matches) === 1) {
            return trim($matches[1]);
        }

        return $trimmed;
    }

    private function logHttpFailure(Response $response): void
    {
        if (! (bool) config('receiptscanner.logging', false)) {
            return;
        }

        Log::channel((string) config('logging.default', 'stack'))->warning('OpenAI receipt extraction HTTP error.', [
            'provider' => 'openai',
            'endpoint_mode' => 'responses',
            'status' => $response->status(),
            'request_id' => $this->requestIdFromResponse($response),
            'message' => $this->safeResponseMessage($response),
        ]);
    }

    private function requestIdFromResponse(Response $response): ?string
    {
        foreach (['x-request-id', 'openai-request-id', 'request-id'] as $header) {
            $value = $response->header($header);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function safeResponseMessage(Response $response): string
    {
        $json = $response->json();
        $message = '';

        if (is_array($json)) {
            $error = $json['error'] ?? null;

            if (is_array($error) && is_string($error['message'] ?? null)) {
                $message = $error['message'];
            } elseif (is_string($json['message'] ?? null)) {
                $message = $json['message'];
            }
        }

        if ($message === '') {
            $message = $response->body();
        }

        return $this->redactSensitiveText(mb_substr(trim($message), 0, 1000));
    }

    private function redactSensitiveText(string $text): string
    {
        $text = preg_replace('/Bearer\s+[A-Za-z0-9._\-]+/i', 'Bearer [redacted]', $text) ?? $text;
        $text = preg_replace('/(api[-_]?key\s*[:=]\s*)[^\s,}]+/i', '$1[redacted]', $text) ?? $text;
        $text = preg_replace('/data:(?:image|application)\/[A-Za-z0-9.+-]+;base64,[A-Za-z0-9+\/=\r\n]+/i', '[redacted-file-data]', $text) ?? $text;

        return $text;
    }
}

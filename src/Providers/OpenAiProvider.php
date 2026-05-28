<?php

declare(strict_types=1);

namespace Jake142\ReceiptScanner\Providers;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Jake142\ReceiptScanner\Prompt\ReceiptPrompt;
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
        $model = (string) ($options['model'] ?? config('receiptscanner.providers.openai.model', 'gpt-5.4-nano'));
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

        return $this->normalizeReceiptPayload($decoded, $fields);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<int, string>
     */
    private function fieldsFromContext(array $context): array
    {
        $prompt = new ReceiptPrompt();
        $canonical = $prompt->fields();
        $enabled = $this->normalizeEnabledFields($context['fields'] ?? null);

        if ($enabled === []) {
            $enabled = $this->normalizeEnabledFields(config('receiptscanner.enabled_fields', []));
        }

        if ($enabled === []) {
            return $canonical;
        }

        $fields = [];

        foreach ($canonical as $field) {
            if (in_array($field, $enabled, true)) {
                $fields[] = $field;
            }
        }

        return $fields === [] ? $canonical : $fields;
    }

    /**
     * @param mixed $fields
     * @return array<int, string>
     */
    private function normalizeEnabledFields(mixed $fields): array
    {
        if (is_string($fields)) {
            $fields = array_map('trim', explode(',', $fields));
        }

        if (! is_array($fields) || $fields === []) {
            return [];
        }

        $normalized = [];

        foreach ($fields as $key => $value) {
            if (is_int($key) && is_string($value)) {
                $field = strtolower(trim($value));
                if ($field !== '') {
                    $normalized[$field] = true;
                }
                continue;
            }

            if (is_string($key) && is_bool($value)) {
                if ($value) {
                    $normalized[strtolower(trim($key))] = true;
                }
                continue;
            }

            if (is_string($key) && is_scalar($value) && filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
                $normalized[strtolower(trim($key))] = true;
            }
        }

        return array_values(array_keys($normalized));
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
                'text' => $this->buildPrompt($fields, $context),
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
     * @param array<string, mixed> $context
     */
    private function buildPrompt(array $fields, array $context): string
    {
        $inputType = (string) ($context['input_type'] ?? 'images');
        $isPdf = strtolower($inputType) === 'pdf';
        $suffix = 'Extract receipt data from the attached ' . ($isPdf ? 'PDF' : 'image(s)') . '. '
            . 'Analyze all provided pages/images together as one receipt and return a single JSON object only. '
            . 'Requested fields: ' . implode(', ', $fields) . '.';
        $base = config('receiptscanner.prompt.extraction');
        $base = is_string($base) ? trim($base) : '';
        if ($base === '') {
            return trim($suffix);
        }
        return $base . "\n\n" . trim($suffix);
    }

    /**
     * @param array<string, mixed> $context
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
        $prompt = new ReceiptPrompt();
        $schema = $prompt->jsonSchema($fields, 'images');

        return $schema;
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

    /**
     * Normalize legacy provider payloads into the canonical receipt schema.
     *
     * @param array<string, mixed> $payload
     * @param array<int, string> $fields
     * @return array<string, mixed>
     */
    private function normalizeReceiptPayload(array $payload, array $fields): array
    {
        $canonical = [
            'merchant' => $this->normalizeNullableString($payload['merchant'] ?? null),
            'total_amount' => $this->normalizeNullableNumber($payload['total_amount'] ?? $payload['amount'] ?? null),
            'currency' => $this->normalizeNullableString($payload['currency'] ?? null),
            'date' => $this->normalizeNullableDate($payload['date'] ?? null),
            'vat_amount' => $this->normalizeNullableNumber($payload['vat_amount'] ?? $payload['tax_amount'] ?? $payload['vat'] ?? $payload['tax'] ?? null),
            'mcc' => $this->normalizeNullableString($payload['mcc'] ?? null),
            'vats' => $this->normalizeVats($payload),
            'line_items' => $this->normalizeLineItems($payload['line_items'] ?? null),
            'confidence' => $this->normalizeNullableNumber($payload['confidence'] ?? null),
            'tip' => $this->normalizeNullableNumber($payload['tip'] ?? null),
            'purchase_country' => $this->normalizeNullableString($payload['purchase_country'] ?? null),
            'purchase_city' => $this->normalizeNullableString($payload['purchase_city'] ?? null),
        ];

        $result = [];

        foreach ($fields as $field) {
            if (array_key_exists($field, $canonical)) {
                $result[$field] = $canonical[$field];
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    private function normalizeVats(array $payload): array
    {
        $rows = [];

        $source = $payload['vats'] ?? null;
        if (is_array($source)) {
            foreach ($source as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $rows[] = $this->normalizeVatRow($row);
            }
        }

        if ($rows !== []) {
            return $rows;
        }

        $legacyRow = [
            'rate' => $payload['vat_rate'] ?? $payload['tax_rate'] ?? null,
            'amount' => $payload['vat_amount'] ?? $payload['tax_amount'] ?? $payload['tax'] ?? $payload['vat'] ?? null,
            'amount_ex_vat' => $payload['amount_excluding_vat'] ?? $payload['subtotal'] ?? null,
            'amount_inc_vat' => $payload['amount_including_vat'] ?? $payload['total'] ?? $payload['amount'] ?? null,
        ];

        if ($legacyRow['rate'] !== null || $legacyRow['amount'] !== null || $legacyRow['amount_ex_vat'] !== null || $legacyRow['amount_inc_vat'] !== null) {
            return [$this->normalizeVatRow($legacyRow)];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeVatRow(array $row): array
    {
        return [
            'rate' => $this->normalizeNullableNumber($row['rate'] ?? $row['vat_rate'] ?? null),
            'amount' => $this->normalizeNullableNumber($row['amount'] ?? $row['vat_amount'] ?? $row['tax_amount'] ?? null),
            'amount_inc_vat' => $this->normalizeNullableNumber($row['amount_inc_vat'] ?? $row['amount_including_vat'] ?? $row['gross_amount'] ?? null),
            'amount_ex_vat' => $this->normalizeNullableNumber($row['amount_ex_vat'] ?? $row['amount_excluding_vat'] ?? $row['net_amount'] ?? null),
        ];
    }

    private function normalizeLineItems(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = [];

        foreach ($value as $item) {
            if (! is_array($item)) {
                continue;
            }

            $normalized[] = [
                'description' => $this->normalizeNullableString($item['description'] ?? null),
                'quantity' => $this->normalizeNullableNumber($item['quantity'] ?? null),
                'unit_price' => $this->normalizeNullableNumber($item['unit_price'] ?? null),
                'amount' => $this->normalizeNullableNumber($item['amount'] ?? $item['amount_including_vat'] ?? null),
            ];
        }

        return $normalized;
    }

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

    private function normalizeNullableDate(mixed $value): ?string
    {
        $value = $this->normalizeNullableString($value);

        if ($value === null) {
            return null;
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }
}

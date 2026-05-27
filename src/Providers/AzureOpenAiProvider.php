<?php

declare(strict_types=1);

namespace Jake142\ReceiptScanner\Providers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

class AzureOpenAiProvider
{
    public function extract(array $context): array
    {
        $config = $this->providerConfig();
        $mode = $this->endpointMode($config);
        $deployment = $this->resolveDeployment($context, $config);
        $endpoint = $this->requiredConfig($config, 'endpoint', 'AZURE_OPENAI_ENDPOINT');
        $apiKey = $this->requiredConfig($config, 'api_key', 'AZURE_OPENAI_API_KEY');
        $url = $this->buildResponsesUrl($endpoint, $mode, $config);
        $fields = $this->resolveFields($context);
        $payload = $this->buildPayload($deployment, $context, $fields);

        $response = $this->send($url, $apiKey, $payload, $mode, $context);

        if (! $response->successful()) {
            $this->throwProviderHttpException($response, $mode);
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new RuntimeException('Azure OpenAI returned a non-JSON response.');
        }

        $parsed = $this->extractParsedReceipt($data);

        if ($parsed === null) {
            $text = $this->extractResponseText($data, $response, $mode);
            $parsed = $this->decodeJsonText($text, $response, $mode);
        }

        return $this->coerceReceiptFields($parsed, $fields);
    }

    private function providerConfig(): array
    {
        $config = config('receiptscanner.providers.azure_openai', []);

        return is_array($config) ? $config : [];
    }

    private function endpointMode(array $config): string
    {
        $mode = strtolower(trim((string) ($config['endpoint_mode'] ?? 'v1')));

        if ($mode === '') {
            return 'v1';
        }

        if (! in_array($mode, ['v1', 'legacy'], true)) {
            throw new InvalidArgumentException('Invalid Azure OpenAI endpoint_mode. Supported values are v1 and legacy.');
        }

        return $mode;
    }

    private function resolveDeployment(array $context, array $config): string
    {
        $options = is_array($context['options'] ?? null) ? $context['options'] : [];
        $deployment = trim((string) ($options['model'] ?? $config['deployment'] ?? ''));

        if ($deployment === '') {
            throw new InvalidArgumentException('Missing Azure OpenAI deployment. Set AZURE_OPENAI_DEPLOYMENT or receiptscanner.providers.azure_openai.deployment.');
        }

        return $deployment;
    }

    private function requiredConfig(array $config, string $key, string $envKey): string
    {
        $value = trim((string) ($config[$key] ?? ''));

        if ($value === '') {
            throw new InvalidArgumentException('Missing Azure OpenAI configuration for '.$key.'. Set '.$envKey.'.');
        }

        return $value;
    }

    private function buildResponsesUrl(string $endpoint, string $mode, array $config): string
    {
        $base = rtrim(trim($endpoint), '/');

        if ($base === '') {
            throw new InvalidArgumentException('Missing Azure OpenAI endpoint. Set AZURE_OPENAI_ENDPOINT.');
        }

        if ($mode === 'v1') {
            return $base.'/openai/v1/responses';
        }

        $apiVersion = trim((string) ($config['api_version'] ?? ''));

        if ($apiVersion === '') {
            throw new InvalidArgumentException('Missing Azure OpenAI API version for legacy endpoint mode. Set AZURE_OPENAI_API_VERSION.');
        }

        return $base.'/openai/responses?'.http_build_query(['api-version' => $apiVersion], '', '&', PHP_QUERY_RFC3986);
    }

    private function buildPayload(string $deployment, array $context, array $fields): array
    {
        $content = [
            [
                'type' => 'input_text',
                'text' => $this->buildPrompt($fields),
            ],
        ];

        foreach ($this->normalizePaths($context) as $index => $path) {
            $content[] = $this->buildFilePart($path, $context, $index);
        }

        return [
            'model' => $deployment,
            'input' => [
                [
                    'role' => 'user',
                    'content' => $content,
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'receipt_scan_result',
                    'strict' => true,
                    'schema' => $this->receiptSchema($fields),
                ],
            ],
        ];
    }

    private function buildPrompt(array $fields): string
    {
        return 'Extract structured receipt data from the attached receipt image or PDF. Return only a JSON object matching the provided schema. Use null for unknown scalar values, an empty array for missing line_items, and an empty object for missing metadata. Requested top-level fields: '.implode(', ', $fields).'.';
    }

    private function normalizePaths(array $context): array
    {
        $paths = $context['paths'] ?? ($context['path'] ?? []);

        if (is_string($paths)) {
            $paths = [$paths];
        }

        if (! is_array($paths)) {
            $paths = [];
        }

        $paths = array_values(array_filter(array_map(static fn ($path): string => trim((string) $path), $paths)));

        if ($paths === []) {
            throw new InvalidArgumentException('ReceiptScanner Azure OpenAI provider requires at least one input path.');
        }

        return $paths;
    }

    private function buildFilePart(string $path, array $context, int $index): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException('Receipt input file is not readable: '.$path);
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new InvalidArgumentException('Receipt input file could not be read: '.$path);
        }

        $mimeType = $this->resolveMimeType($path, $context, $index);
        $dataUrl = 'data:'.$mimeType.';base64,'.base64_encode($contents);

        if ($this->isPdf($path, $mimeType, $context)) {
            return [
                'type' => 'input_file',
                'filename' => basename($path),
                'file_data' => $dataUrl,
            ];
        }

        return [
            'type' => 'input_image',
            'image_url' => $dataUrl,
            'detail' => 'auto',
        ];
    }

    private function resolveMimeType(string $path, array $context, int $index): string
    {
        $options = is_array($context['options'] ?? null) ? $context['options'] : [];
        $mimeType = $context['mime_type'] ?? ($options['mime_type'] ?? null);

        if (is_array($mimeType)) {
            $mimeType = $mimeType[$index] ?? null;
        }

        if (is_string($mimeType) && trim($mimeType) !== '') {
            return trim($mimeType);
        }

        $detected = false;

        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);

            if ($finfo !== false) {
                $detected = finfo_file($finfo, $path);
                finfo_close($finfo);
            }
        }

        if (is_string($detected) && $detected !== '') {
            return $detected;
        }

        return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'pdf' ? 'application/pdf' : 'image/jpeg';
    }

    private function isPdf(string $path, string $mimeType, array $context): bool
    {
        $inputType = strtolower((string) ($context['input_type'] ?? ''));

        return $inputType === 'pdf'
            || str_contains(strtolower($mimeType), 'pdf')
            || strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'pdf';
    }

    private function resolveFields(array $context): array
    {
        $options = is_array($context['options'] ?? null) ? $context['options'] : [];
        $fields = $context['fields'] ?? ($options['fields'] ?? config('receiptscanner.fields', []));

        if (is_string($fields)) {
            $fields = array_map('trim', explode(',', $fields));
        }

        if (! is_array($fields)) {
            $fields = [];
        }

        $fields = array_values(array_unique(array_filter(array_map(static fn ($field): string => trim((string) $field), $fields))));

        if ($fields === []) {
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

        return $fields;
    }

    private function receiptSchema(array $fields): array
    {
        $properties = [];

        foreach ($fields as $field) {
            $properties[$field] = $this->schemaForField($field);
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_values($fields),
            'additionalProperties' => false,
        ];
    }

    private function schemaForField(string $field): array
    {
        return match ($field) {
            'merchant', 'date', 'currency', 'mcc' => ['type' => ['string', 'null']],
            'total', 'amount', 'tax', 'vat', 'confidence' => ['type' => ['number', 'null']],
            'line_items' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'description' => ['type' => ['string', 'null']],
                        'quantity' => ['type' => ['number', 'null']],
                        'unit_price' => ['type' => ['number', 'null']],
                        'total' => ['type' => ['number', 'null']],
                    ],
                    'required' => ['description', 'quantity', 'unit_price', 'total'],
                    'additionalProperties' => false,
                ],
            ],
            'metadata' => [
                'type' => 'object',
                'properties' => [
                    'payment_method' => ['type' => ['string', 'null']],
                    'receipt_number' => ['type' => ['string', 'null']],
                    'store_address' => ['type' => ['string', 'null']],
                    'raw_locale' => ['type' => ['string', 'null']],
                    'notes' => ['type' => ['string', 'null']],
                ],
                'required' => ['payment_method', 'receipt_number', 'store_address', 'raw_locale', 'notes'],
                'additionalProperties' => false,
            ],
            default => ['type' => ['string', 'number', 'boolean', 'null']],
        };
    }

    private function send(string $url, string $apiKey, array $payload, string $mode, array $context): Response
    {
        $options = is_array($context['options'] ?? null) ? $context['options'] : [];
        $timeout = max(1, (int) ($options['timeout'] ?? config('receiptscanner.timeout', 60)));
        $retries = max(0, (int) ($options['retries'] ?? config('receiptscanner.retries', 2)));
        $maxAttempts = $retries + 1;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = Http::withHeaders([
                    'api-key' => $apiKey,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ])->timeout($timeout)->asJson()->post($url, $payload);
            } catch (ConnectionException $exception) {
                if ($attempt < $maxAttempts) {
                    $this->pauseBeforeRetry($attempt);
                    continue;
                }

                $this->throwConnectionException($exception, $mode);
            }

            if (! $this->shouldRetry($response, $attempt, $maxAttempts)) {
                return $response;
            }

            $this->pauseBeforeRetry($attempt);
        }

        throw new RuntimeException('Azure OpenAI request failed before a response was received.');
    }

    private function shouldRetry(Response $response, int $attempt, int $maxAttempts): bool
    {
        if ($attempt >= $maxAttempts) {
            return false;
        }

        return $response->status() === 429 || $response->status() >= 500;
    }

    private function pauseBeforeRetry(int $attempt): void
    {
        usleep(min(1000000, 200000 * $attempt));
    }

    private function throwConnectionException(ConnectionException $exception, string $mode): void
    {
        $diagnostic = [
            'provider' => 'azure_openai',
            'endpoint_mode' => $mode,
            'status' => null,
            'request_id' => null,
            'message' => $this->safeExcerpt($exception->getMessage()),
        ];

        $this->logSafeDiagnostic('ReceiptScanner Azure OpenAI connection failed', $diagnostic);

        throw new RuntimeException('Azure OpenAI request failed: '.$diagnostic['message'], 0, $exception);
    }

    private function throwProviderHttpException(Response $response, string $mode): void
    {
        $diagnostic = [
            'provider' => 'azure_openai',
            'endpoint_mode' => $mode,
            'status' => $response->status(),
            'request_id' => $this->requestId($response),
            'message' => $this->safeResponseExcerpt($response),
        ];

        $this->logSafeDiagnostic('ReceiptScanner Azure OpenAI request failed', $diagnostic);

        throw new RuntimeException('Azure OpenAI request failed with HTTP '.$response->status().': '.$diagnostic['message']);
    }

    private function extractParsedReceipt(array $data): ?array
    {
        if (isset($data['parsed']) && is_array($data['parsed'])) {
            return $data['parsed'];
        }

        foreach (($data['output'] ?? []) as $output) {
            if (! is_array($output)) {
                continue;
            }

            foreach (($output['content'] ?? []) as $content) {
                if (is_array($content) && isset($content['parsed']) && is_array($content['parsed'])) {
                    return $content['parsed'];
                }
            }
        }

        return null;
    }

    private function extractResponseText(array $data, Response $response, string $mode): string
    {
        $candidates = [];

        if (isset($data['output_text']) && is_string($data['output_text'])) {
            $candidates[] = $data['output_text'];
        }

        foreach (($data['output'] ?? []) as $output) {
            if (! is_array($output)) {
                continue;
            }

            foreach (($output['content'] ?? []) as $content) {
                if (is_array($content) && isset($content['text']) && is_string($content['text'])) {
                    $candidates[] = $content['text'];
                }
            }
        }

        $choiceContent = $data['choices'][0]['message']['content'] ?? null;

        if (is_string($choiceContent)) {
            $candidates[] = $choiceContent;
        }

        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);

            if ($candidate !== '') {
                return $candidate;
            }
        }

        $diagnostic = [
            'provider' => 'azure_openai',
            'endpoint_mode' => $mode,
            'status' => $response->status(),
            'request_id' => $this->requestId($response),
            'message' => $this->safeResponseExcerpt($response),
        ];

        throw new RuntimeException('Azure OpenAI response did not include text output: '.$diagnostic['message']);
    }

    private function decodeJsonText(string $text, Response $response, string $mode): array
    {
        $candidates = [$text, $this->stripCodeFence($text)];
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start !== false && $end !== false && $end >= $start) {
            $candidates[] = substr($text, $start, $end - $start + 1);
        }

        foreach ($candidates as $candidate) {
            $decoded = json_decode(trim($candidate), true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $diagnostic = [
            'provider' => 'azure_openai',
            'endpoint_mode' => $mode,
            'status' => $response->status(),
            'request_id' => $this->requestId($response),
            'message' => $this->safeExcerpt($text),
        ];

        throw new RuntimeException('Azure OpenAI response text was not valid receipt JSON: '.json_last_error_msg().'. '.$diagnostic['message']);
    }

    private function stripCodeFence(string $text): string
    {
        $text = trim($text);

        if (str_starts_with($text, '```')) {
            $text = preg_replace('~^```[a-zA-Z0-9_-]*~', '', $text) ?? $text;
            $text = preg_replace('~```$~', '', trim($text)) ?? $text;
        }

        return trim($text);
    }

    private function coerceReceiptFields(array $result, array $fields): array
    {
        foreach ($fields as $field) {
            if (! array_key_exists($field, $result)) {
                $result[$field] = $this->defaultValueForField($field);
            }
        }

        return $result;
    }

    private function defaultValueForField(string $field): mixed
    {
        return match ($field) {
            'line_items', 'metadata' => [],
            default => null,
        };
    }

    private function requestId(Response $response): ?string
    {
        foreach (['x-request-id', 'apim-request-id', 'x-ms-request-id', 'x-ms-client-request-id'] as $header) {
            $value = $response->header($header);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function safeResponseExcerpt(Response $response): string
    {
        return $this->safeExcerpt($response->body());
    }

    private function safeExcerpt(string $value): string
    {
        $value = $this->redactSensitiveContent($value);
        $value = preg_replace('~[[:cntrl:]]+~', ' ', $value) ?? $value;
        $value = trim($value);

        if ($value === '') {
            return 'No response body.';
        }

        return substr($value, 0, 700);
    }

    private function redactSensitiveContent(string $value): string
    {
        $redacted = preg_replace('~data:[^;]+;base64,[A-Za-z0-9+/=]+~i', '[redacted-base64-data]', $value) ?? $value;
        $redacted = preg_replace('~[A-Za-z0-9+/]{200,}={0,2}~', '[redacted-long-base64]', $redacted) ?? $redacted;
        $redacted = preg_replace('~(api-key|authorization|api_key|access_token|token)([^,}]*)~i', '$1[redacted]', $redacted) ?? $redacted;

        return $redacted;
    }

    private function logSafeDiagnostic(string $message, array $diagnostic): void
    {
        if (! config('receiptscanner.logging', false)) {
            return;
        }

        try {
            Log::channel((string) (config('logging.default') ?: 'stack'))->warning($message, $diagnostic);
        } catch (\Throwable) {
        }
    }
}

<?php

declare(strict_types=1);

namespace Jake142\ReceiptScanner\Providers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Jake142\ReceiptScanner\Prompt\ReceiptPrompt;
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

        return $this->normalizeReceipt($parsed, $fields);
    }

    private function providerConfig(): array
    {
        $config = config('receiptscanner.providers.azure_openai', []);

        return is_array($config) ? $config : [];
    }

    private function endpointMode(array $config): string
    {
        $apiVersion = trim((string) ($config['api_version'] ?? ''));

        if ($apiVersion === '' || $apiVersion === 'v1') {
            return 'v1';
        }

        return 'legacy';
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
        $suffix = 'Extract structured receipt data from the attached receipt image or PDF. '
        . 'Analyze all provided images together as one receipt and merge them in order. '
        . 'Return only a JSON object matching the provided schema. '
        . 'Requested top-level fields: ' . implode(', ', $fields) . '.';
        $base = config('receiptscanner.prompt.extraction');
        $base = is_string($base) ? trim($base) : '';
        if ($base === '') {
            return trim($suffix);
        }
        return $base . "\n\n" . trim($suffix);
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
        $configured = $context['fields'] ?? ($options['fields'] ?? config('receiptscanner.enabled_fields', []));
        $canonical = (new ReceiptPrompt())->fields();

        if (is_string($configured)) {
            $configured = array_map('trim', explode(',', $configured));
        }

        if (! is_array($configured)) {
            $configured = [];
        }

        $requested = [];

        foreach ($configured as $key => $value) {
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

        $fields = [];

        foreach ($canonical as $field) {
            if (isset($requested[$field])) {
                $fields[] = $field;
            }
        }

        return $fields === [] ? $canonical : $fields;
    }

    private function receiptSchema(array $fields): array
    {
        return (new ReceiptPrompt())->jsonSchema($fields, 'images');
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
            'request_id' => $this->requestIdFromResponse($response),
            'message' => $this->safeResponseMessage($response),
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
            'request_id' => $this->requestIdFromResponse($response),
            'message' => $this->safeResponseMessage($response),
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
            'request_id' => $this->requestIdFromResponse($response),
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

    private function normalizeReceipt(array $result, array $fields): array
    {
        $canonical = [
            'merchant' => null,
            'total_amount' => null,
            'currency' => null,
            'date' => null,
            'vat_amount' => null,
            'mcc' => null,
            'vats' => [],
            'line_items' => [],
            'confidence' => null,
            'tip' => null,
            'purchase_country' => null,
            'purchase_city' => null,
        ];

        $canonical['merchant'] = $this->normalizeNullableString($result['merchant'] ?? null);
        $canonical['total_amount'] = $this->normalizeNullableNumber($result['total_amount'] ?? $result['amount'] ?? null);
        $canonical['currency'] = $this->normalizeNullableString($result['currency'] ?? null);
        $canonical['date'] = $this->normalizeNullableDate($result['date'] ?? null);
        $canonical['vat_amount'] = $this->normalizeNullableNumber($result['vat_amount'] ?? $result['tax_amount'] ?? $result['vat'] ?? $result['tax'] ?? null);
        $canonical['mcc'] = $this->normalizeNullableString($result['mcc'] ?? null);
        $canonical['vats'] = $this->normalizeVats($result);
        $canonical['line_items'] = $this->normalizeLineItems($result['line_items'] ?? null);
        $canonical['confidence'] = $this->normalizeNullableNumber($result['confidence'] ?? null);
        $canonical['tip'] = $this->normalizeNullableNumber($result['tip'] ?? null);
        $canonical['purchase_country'] = $this->normalizeNullableString($result['purchase_country'] ?? null);
        $canonical['purchase_city'] = $this->normalizeNullableString($result['purchase_city'] ?? null);

        $output = [];

        foreach ($fields as $field) {
            if (array_key_exists($field, $canonical)) {
                $output[$field] = $canonical[$field];
            }
        }

        return $output;
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

    private function normalizeVats(array $result): array
    {
        $vats = $result['vats'] ?? null;
        $rows = is_array($vats) ? $vats : [];

        if ($rows === []) {
            $legacyVat = $result['vat'] ?? $result['tax'] ?? null;

            if (is_array($legacyVat)) {
                $rows = $legacyVat;
            } elseif ($legacyVat !== null || isset($result['vat_amount']) || isset($result['tax_amount'])) {
                $rows = [[
                    'rate' => $result['vat_rate'] ?? $result['tax_rate'] ?? null,
                    'amount' => $result['vat_amount'] ?? $result['tax_amount'] ?? $legacyVat,
                    'amount_inc_vat' => $result['amount_including_vat'] ?? $result['total_amount'] ?? $result['amount'] ?? null,
                    'amount_ex_vat' => $result['amount_excluding_vat'] ?? $result['subtotal'] ?? null,
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
                'amount_inc_vat' => $this->normalizeNullableNumber($row['amount_inc_vat'] ?? $row['amount_including_vat'] ?? $row['gross_amount'] ?? $result['total_amount'] ?? $result['amount'] ?? null),
                'amount_ex_vat' => $this->normalizeNullableNumber($row['amount_ex_vat'] ?? $row['amount_excluding_vat'] ?? $row['net_amount'] ?? null),
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

    private function requestIdFromResponse(Response $response): ?string
    {
        foreach (['apim-request-id', 'x-ms-request-id', 'x-request-id', 'openai-request-id', 'request-id'] as $header) {
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

        return $this->safeExcerpt($message);
    }

    private function logSafeDiagnostic(string $message, array $diagnostic): void
    {
        if (! (bool) config('receiptscanner.logging.enabled', false)) {
            return;
        }

        try {
            $channel = config('receiptscanner.logging.channel');

            Log::channel(is_string($channel) && $channel !== '' ? $channel : (string) (config('logging.default') ?: 'stack'))
                ->warning($message, $diagnostic);
        } catch (\Throwable) {
        }
    }
}

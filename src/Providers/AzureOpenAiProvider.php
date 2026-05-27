<?php

declare(strict_types=1);

namespace Jake142\ReceiptScanner\Providers;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Jake142\ReceiptScanner\Providers\Concerns\ExtractsOpenAiResponsesText;
use JsonException;
use RuntimeException;
use stdClass;

class AzureOpenAiProvider
{
    use ExtractsOpenAiResponsesText;

    /**
     * Send a receipt image or PDF to the Azure OpenAI Responses API and return
     * the structured receipt JSON produced by the model.
     *
     * Expected context keys are the package's internal ProviderExtractContext
     * shape: path, contents, mime_type, filename, and options.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function extract(array $context): array
    {
        $options = $this->contextOptions($context);
        $endpoint = $this->configuredString('receiptscanner.providers.azure_openai.endpoint', $options['endpoint'] ?? null);

        if ($endpoint === '') {
            throw new RuntimeException('Azure OpenAI endpoint is not configured.');
        }

        $apiKey = $this->configuredString('receiptscanner.providers.azure_openai.api_key', $options['api_key'] ?? null);
        $authToken = $this->configuredString('receiptscanner.providers.azure_openai.auth_token', $options['auth_token'] ?? null);

        if ($apiKey === '' && $authToken === '') {
            throw new RuntimeException('Azure OpenAI API key or auth token is not configured.');
        }

        $model = $this->azureModel($options);

        if ($model === '') {
            throw new RuntimeException('Azure OpenAI deployment or model is not configured.');
        }

        $request = Http::acceptJson()
            ->asJson()
            ->timeout($this->requestTimeout($options));

        if ($apiKey !== '') {
            $request = $request->withHeaders(['api-key' => $apiKey]);
        } else {
            $request = $request->withToken($authToken);
        }

        $response = $request->post(
            $this->responsesUrl($endpoint, $options),
            $this->buildResponsesPayload($context, $model, $options),
        );

        if ($response->failed()) {
            throw $this->azureOpenAiRequestException($response);
        }

        $body = $this->decodeResponseBody($response);
        $generatedText = $this->extractOpenAiResponsesText($body);

        if ($generatedText === null || trim($generatedText) === '') {
            throw new RuntimeException('No generated text was found in Azure OpenAI response.');
        }

        return $this->decodeReceiptJson($generatedText);
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function buildResponsesPayload(array $context, string $model, array $options): array
    {
        if (array_key_exists('input', $options)) {
            $payload = [
                'model' => $model,
                'input' => $options['input'],
            ];
        } else {
            $content = [
                [
                    'type' => 'input_text',
                    'text' => $this->prompt($options),
                ],
                $this->inputFileBlock($context),
            ];

            $payload = [
                'model' => $model,
                'input' => [
                    [
                        'role' => 'user',
                        'content' => $content,
                    ],
                ],
            ];
        }

        foreach (['temperature', 'top_p', 'max_output_tokens', 'metadata', 'reasoning', 'tools', 'tool_choice'] as $key) {
            if (array_key_exists($key, $options)) {
                $payload[$key] = $options[$key];
            }
        }

        if (array_key_exists('text', $options)) {
            $payload['text'] = $options['text'];
        } elseif (array_key_exists('text_format', $options)) {
            $payload['text'] = ['format' => $options['text_format']];
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, string>
     */
    private function inputFileBlock(array $context): array
    {
        $path = is_string($context['path'] ?? null) ? $context['path'] : null;
        $contents = is_string($context['contents'] ?? null) ? $context['contents'] : null;
        $mimeType = $this->mimeType($context, $path, $contents);
        $filename = $this->filename($context, $path, $mimeType);

        if ($contents === null && $path !== null && $this->isRemoteUrl($path)) {
            if ($mimeType === 'application/pdf') {
                return [
                    'type' => 'input_file',
                    'file_url' => $path,
                ];
            }

            if (str_starts_with($mimeType, 'image/')) {
                return [
                    'type' => 'input_image',
                    'image_url' => $path,
                ];
            }
        }

        if ($contents === null && $path !== null && is_file($path) && is_readable($path)) {
            $contents = file_get_contents($path);

            if ($contents === false) {
                throw new RuntimeException(sprintf('Unable to read receipt file at path [%s].', $path));
            }
        }

        if ($contents === null || $contents === '') {
            throw new RuntimeException('Receipt contents are empty or could not be read.');
        }

        $dataUrl = sprintf('data:%s;base64,%s', $mimeType, base64_encode($contents));

        if ($mimeType === 'application/pdf') {
            return [
                'type' => 'input_file',
                'filename' => $filename,
                'file_data' => $dataUrl,
            ];
        }

        if (str_starts_with($mimeType, 'image/')) {
            return [
                'type' => 'input_image',
                'image_url' => $dataUrl,
            ];
        }

        throw new RuntimeException(sprintf('Unsupported receipt MIME type [%s]. Azure OpenAI receipt extraction supports images and PDFs.', $mimeType));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function prompt(array $options): string
    {
        if (isset($options['prompt']) && is_string($options['prompt']) && trim($options['prompt']) !== '') {
            return $options['prompt'];
        }

        return implode("\n", [
            'Extract the receipt information from the provided image or PDF.',
            'Return only valid JSON using this shape:',
            '{"merchant":null,"date":null,"total":null,"subtotal":null,"tax":null,"currency":null,"items":[],"raw":null}',
            'Use numbers for monetary values when possible. Use null when a value is not present. Do not include markdown or explanatory text.',
        ]);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function contextOptions(array $context): array
    {
        return isset($context['options']) && is_array($context['options']) ? $context['options'] : [];
    }

    /**
     * @param array<string, mixed> $options
     */
    private function azureModel(array $options): string
    {
        foreach (['deployment', 'model'] as $optionKey) {
            if (isset($options[$optionKey]) && is_string($options[$optionKey]) && trim($options[$optionKey]) !== '') {
                return trim($options[$optionKey]);
            }
        }

        $deployment = config('receiptscanner.providers.azure_openai.deployment');

        if (is_string($deployment) && trim($deployment) !== '') {
            return trim($deployment);
        }

        $model = config('receiptscanner.providers.azure_openai.model');

        return is_string($model) ? trim($model) : '';
    }

    /**
     * @param array<string, mixed> $options
     */
    private function requestTimeout(array $options): int
    {
        $timeout = $options['timeout'] ?? 60;

        if (is_int($timeout) && $timeout > 0) {
            return $timeout;
        }

        if (is_numeric($timeout) && (int) $timeout > 0) {
            return (int) $timeout;
        }

        return 60;
    }

    private function configuredString(string $configKey, mixed $override = null): string
    {
        $value = $override ?? config($configKey);

        return is_string($value) ? trim($value) : '';
    }

    /**
     * @param array<string, mixed> $options
     */
    private function responsesUrl(string $endpoint, array $options): string
    {
        $baseUrl = rtrim($endpoint, '/');

        if (str_ends_with($baseUrl, '/openai/v1')) {
            $url = $baseUrl.'/responses';
        } elseif (str_ends_with($baseUrl, '/openai')) {
            $url = $baseUrl.'/v1/responses';
        } else {
            $url = $baseUrl.'/openai/v1/responses';
        }

        $apiVersion = $this->configuredString('receiptscanner.providers.azure_openai.api_version', $options['api_version'] ?? null);

        if ($apiVersion === '') {
            return $url;
        }

        return $url.'?'.http_build_query(['api-version' => $apiVersion], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function mimeType(array $context, ?string $path, ?string $contents): string
    {
        if (isset($context['mime_type']) && is_string($context['mime_type']) && trim($context['mime_type']) !== '') {
            return strtolower(trim($context['mime_type']));
        }

        if ($path !== null && ! $this->isRemoteUrl($path) && is_file($path)) {
            $detected = function_exists('mime_content_type') ? mime_content_type($path) : false;

            if (is_string($detected) && $detected !== '') {
                return strtolower($detected);
            }
        }

        if ($contents !== null && class_exists('finfo')) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $detected = $finfo->buffer($contents);

            if (is_string($detected) && $detected !== '') {
                return strtolower($detected);
            }
        }

        $extension = $path !== null ? strtolower((string) pathinfo(parse_url($path, PHP_URL_PATH) ?: $path, PATHINFO_EXTENSION)) : '';

        return match ($extension) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'application/octet-stream',
        };
    }

    /**
     * @param array<string, mixed> $context
     */
    private function filename(array $context, ?string $path, string $mimeType): string
    {
        if (isset($context['filename']) && is_string($context['filename']) && trim($context['filename']) !== '') {
            return trim($context['filename']);
        }

        if ($path !== null) {
            $basename = basename((string) (parse_url($path, PHP_URL_PATH) ?: $path));

            if ($basename !== '' && $basename !== '.' && $basename !== '/') {
                return $basename;
            }
        }

        return match ($mimeType) {
            'application/pdf' => 'receipt.pdf',
            'image/png' => 'receipt.png',
            'image/gif' => 'receipt.gif',
            'image/webp' => 'receipt.webp',
            default => 'receipt.jpg',
        };
    }

    private function isRemoteUrl(string $path): bool
    {
        return str_starts_with($path, 'http://') || str_starts_with($path, 'https://');
    }

    private function azureOpenAiRequestException(Response $response): RuntimeException
    {
        $message = sprintf('Azure OpenAI request failed with HTTP status %d.', $response->status());
        $body = $response->json();

        if (is_array($body)) {
            $errorMessage = $this->nestedString($body, ['error', 'message'])
                ?? $this->nestedString($body, ['message']);

            if ($errorMessage !== null && trim($errorMessage) !== '') {
                $message .= ' '.$errorMessage;
            }
        } else {
            $plainBody = trim($response->body());

            if ($plainBody !== '') {
                $message .= ' '.$plainBody;
            }
        }

        return new RuntimeException($message);
    }

    /**
     * @return array<string, mixed>|stdClass
     */
    private function decodeResponseBody(Response $response): array|stdClass
    {
        $decoded = $response->json();

        if (is_array($decoded)) {
            return $decoded;
        }

        try {
            $decoded = json_decode($response->body(), false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Azure OpenAI response was not valid JSON.', previous: $exception);
        }

        if ($decoded instanceof stdClass) {
            return $decoded;
        }

        throw new RuntimeException('Azure OpenAI response JSON did not contain an object.');
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeReceiptJson(string $generatedText): array
    {
        $json = trim($generatedText);

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $decoded = $this->decodeJsonFromTextBlock($json);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('Azure OpenAI generated receipt content was not a JSON object.');
        }

        /** @var array<string, mixed> $receipt */
        $receipt = $decoded;

        if (! array_key_exists('raw', $receipt)) {
            $receipt['raw'] = $generatedText;
        }

        return $receipt;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonFromTextBlock(string $text): array
    {
        $candidate = $this->extractJsonCandidate($text);

        if ($candidate === null) {
            throw new RuntimeException('Azure OpenAI generated receipt content was not valid JSON.');
        }

        try {
            $decoded = json_decode($candidate, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Azure OpenAI generated receipt content was not valid JSON.', previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('Azure OpenAI generated receipt content was not a JSON object.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function extractJsonCandidate(string $text): ?string
    {
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/is', $text, $matches) === 1) {
            return trim($matches[1]);
        }

        $objectStart = strpos($text, '{');
        $objectEnd = strrpos($text, '}');

        if ($objectStart !== false && $objectEnd !== false && $objectEnd > $objectStart) {
            return substr($text, $objectStart, $objectEnd - $objectStart + 1);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $source
     * @param array<int, string> $path
     */
    private function nestedString(array $source, array $path): ?string
    {
        $value = $source;

        foreach ($path as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return is_string($value) ? $value : null;
    }
}

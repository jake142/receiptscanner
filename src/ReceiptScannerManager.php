<?php

declare(strict_types=1);

namespace Jake142\ReceiptScanner;

use Illuminate\Support\Arr;
use InvalidArgumentException;
use Jake142\ReceiptScanner\Providers\AnthropicProvider;
use Jake142\ReceiptScanner\Providers\AzureOpenAiProvider;
use Jake142\ReceiptScanner\Providers\GeminiProvider;
use Jake142\ReceiptScanner\Providers\OpenAiProvider;
use RuntimeException;

class ReceiptScannerManager
{
    public function __construct(
        private ?OpenAiProvider $openAiProvider = null,
        private ?AzureOpenAiProvider $azureOpenAiProvider = null,
        private ?GeminiProvider $geminiProvider = null,
        private ?AnthropicProvider $anthropicProvider = null,
    ) {
        // Do not touch/normalize receiptscanner.provider config here.
        // Provider selection must be driven by config('receiptscanner.default_provider')
        // (and optionally options['provider']).
        $this->openAiProvider ??= new OpenAiProvider();
        $this->azureOpenAiProvider ??= new AzureOpenAiProvider();
        $this->geminiProvider ??= new GeminiProvider();
        $this->anthropicProvider ??= new AnthropicProvider();
    }

    /**
     * Scan a receipt image or PDF and return structured receipt data.
     *
     * The input type is detected from an explicit mime_type option, the local
     * file MIME type when available, or the path extension. PDFs are delegated
     * to scanPdf(); all other supported receipt inputs are delegated to
     * scanImages().
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function scan(string $path, array $options = []): array
    {
        $path = $this->normalizePath($path);

        if ($this->isPdfPath($path, $options)) {
            return $this->scanPdf($path, $options);
        }

        return $this->scanImages([$path], $options);
    }

    /**
     * Scan one or more receipt image paths and return structured receipt data.
     *
     * @param string|array<int, string> $paths
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function scanImages(string|array $paths, array $options = []): array
    {
        $normalizedPaths = $this->normalizePaths($paths);

        $context = $this->buildContext('image', $normalizedPaths, $options);

        return $this->extractWithSelectedProvider($context, $options);
    }

    /**
     * Scan a receipt PDF path and return structured receipt data.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function scanPdf(string $path, array $options = []): array
    {
        $normalizedPath = $this->normalizePath($path);

        $context = $this->buildContext('pdf', [$normalizedPath], $options);

        return $this->extractWithSelectedProvider($context, $options);
    }

    /**
     * @param array<int, string> $paths
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function buildContext(string $inputType, array $paths, array $options): array
    {
        return [
            'input_type' => $inputType,
            'paths' => $paths,
            'mime_type' => $this->mimeTypeForContext($inputType, $paths, $options),
            'options' => $options,
            'fields' => $this->fieldsFromOptions($options),
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function extractWithSelectedProvider(array $context, array $options): array
    {
        $provider = $this->providerForSlug($this->providerSlug($options));

        if (! method_exists($provider, 'extract')) {
            throw new RuntimeException('Configured receipt scanner provider does not expose an extract method.');
        }

        /** @var array<string, mixed> $result */
        $result = $provider->extract($context);

        return $result;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function providerSlug(array $options): string
    {
        // Primary source of truth for the default provider is receiptscanner.default_provider.
        // (The config file also contains receiptscanner.provider for backward compatibility.)
        $provider = $options['provider']
            ?? config('receiptscanner.default_provider')
            ?? config('receiptscanner.provider', 'openai');

        if (! is_string($provider) || trim($provider) === '') {
            throw new InvalidArgumentException('ReceiptScanner provider must be a non-empty string.');
        }

        return strtolower(trim($provider));
    }

    private function providerForSlug(string $slug): object
    {
        return match ($slug) {
            'openai' => $this->openAiProvider,
            'azure_openai' => $this->azureOpenAiProvider,
            'gemini' => $this->geminiProvider,
            'anthropic' => $this->anthropicProvider,
            default => throw new InvalidArgumentException(sprintf(
                'Unsupported ReceiptScanner provider [%s]. Supported providers are openai, azure_openai, gemini, and anthropic.',
                $slug
            )),
        };
    }

    /**
     * @param string|array<int, string> $paths
     * @return array<int, string>
     */
    private function normalizePaths(string|array $paths): array
    {
        if (is_string($paths)) {
            $paths = [$paths];
        }

        $normalized = [];

        foreach ($paths as $path) {
            if (! is_string($path)) {
                throw new InvalidArgumentException('Receipt image paths must be strings.');
            }

            $normalized[] = $this->normalizePath($path);
        }

        if ($normalized === []) {
            throw new InvalidArgumentException('At least one receipt image path is required.');
        }

        return $normalized;
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            throw new InvalidArgumentException('Receipt path must be a non-empty string.');
        }

        return $path;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function isPdfPath(string $path, array $options): bool
    {
        $mimeType = $options['mime_type'] ?? null;

        if (is_string($mimeType) && trim($mimeType) !== '') {
            return strtolower(trim($mimeType)) === 'application/pdf';
        }

        if (is_file($path)) {
            $detected = @mime_content_type($path);

            if (is_string($detected) && trim($detected) !== '') {
                return strtolower(trim($detected)) === 'application/pdf';
            }
        }

        return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'pdf';
    }

    /**
     * @param array<int, string> $paths
     * @param array<string, mixed> $options
     * @return string|array<int, string>|null
     */
    private function mimeTypeForContext(string $inputType, array $paths, array $options): string|array|null
    {
        $mimeType = $options['mime_type'] ?? null;

        if (is_string($mimeType) && trim($mimeType) !== '') {
            return strtolower(trim($mimeType));
        }

        if (is_array($mimeType)) {
            return array_values(array_map(
                static fn ($value): string => is_string($value) ? strtolower(trim($value)) : '',
                $mimeType
            ));
        }

        if ($inputType === 'pdf') {
            return 'application/pdf';
        }

        if (count($paths) === 1) {
            return $this->mimeTypeForPath($paths[0]);
        }

        return array_map(fn (string $path): string => $this->mimeTypeForPath($path), $paths);
    }

    private function mimeTypeForPath(string $path): string
    {
        if (is_file($path)) {
            $detected = @mime_content_type($path);

            if (is_string($detected) && trim($detected) !== '') {
                return strtolower(trim($detected));
            }
        }

        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'application/octet-stream',
        };
    }

    /**
     * @param array<string, mixed> $options
     * @return array<int, string>
     */
    private function fieldsFromOptions(array $options): array
    {
        $fields = $options['fields'] ?? config('receiptscanner.fields', []);

        if (is_string($fields)) {
            $fields = array_map('trim', explode(',', $fields));
        }

        if (! is_array($fields)) {
            $fields = [];
        }

        $normalized = [];
        $hasUsableField = false;

        foreach ($fields as $key => $value) {
            if (is_int($key) && is_string($value)) {
                $field = trim($value);
                if ($field !== '') {
                    $normalized[$field] = true;
                    $hasUsableField = true;
                }
                continue;
            }

            if (is_string($key) && is_bool($value)) {
                if ($value) {
                    $normalized[trim($key)] = true;
                    $hasUsableField = true;
                }
                continue;
            }

            if (is_string($key) && is_scalar($value)) {
                if (filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
                    $normalized[trim($key)] = true;
                    $hasUsableField = true;
                }
            }
        }

        $fields = array_values(array_filter(array_map(
            static fn (string $field): string => strtolower(trim($field)),
            array_keys($normalized)
        ), static fn (string $field): bool => $field !== ''));

        if ($fields !== []) {
            return $fields;
        }

        if ($hasUsableField) {
            return $fields;
        }

        return [
            'merchant',
            'date',
            'amount',
            'currency',
            'mcc',
            'line_items',
            'vats',
            'confidence',
            'metadata',
        ];
    }
}

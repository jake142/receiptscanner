<?php

declare(strict_types=1);

namespace Jake142\ReceiptScanner;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Jake142\ReceiptScanner\Exceptions\ProviderException;
use Jake142\ReceiptScanner\Exceptions\ReceiptScannerException;
use Jake142\ReceiptScanner\Input\FileInput;
use Jake142\ReceiptScanner\Prompt\ReceiptPrompt;
use Jake142\ReceiptScanner\Providers\AnthropicProvider;
use Jake142\ReceiptScanner\Providers\AzureOpenAiProvider;
use Jake142\ReceiptScanner\Providers\GeminiProvider;
use Jake142\ReceiptScanner\Providers\OpenAiProvider;
use JsonException;
use Psr\Log\LoggerInterface;
use Throwable;

class ReceiptScannerService
{
    /** @var array<int, string> */
    private const PROVIDERS = ['openai', 'azure_openai', 'gemini', 'anthropic'];

    /** @var array<int, string> */
    private const DEFAULT_SECTIONS = [
        'merchant',
        'receipt',
        'totals',
        'vat_breakdown',
        'line_items',
        'mcc',
        'confidence',
        'warnings',
        'metadata',
    ];

    public function __construct(
        private readonly ReceiptPrompt $prompt,
        private readonly OpenAiProvider $openAiProvider,
        private readonly AzureOpenAiProvider $azureOpenAiProvider,
        private readonly GeminiProvider $geminiProvider,
        private readonly AnthropicProvider $anthropicProvider,
    ) {
    }

    /**
     * Scan one receipt represented by one or more image files.
     *
     * @param array<int, mixed> $images
     * @return array<string, mixed>
     */
    public function scanImages(array $images): array
    {
        if ($images === []) {
            throw new InvalidArgumentException('scanImages requires at least one image.');
        }

        $maxImages = max(1, (int) config('receiptscanner.max_images', 20));
        if (count($images) > $maxImages) {
            throw new InvalidArgumentException(sprintf('scanImages accepts at most %d images.', $maxImages));
        }

        $maxFileSizeMb = max(1, (int) config('receiptscanner.max_file_size_mb', 20));
        $files = array_map(
            static fn (mixed $image): FileInput => FileInput::image($image, $maxFileSizeMb),
            array_values($images),
        );

        return $this->scan('images', $files);
    }

    /**
     * Scan one receipt represented by exactly one PDF file.
     *
     * @param mixed $pdf
     * @return array<string, mixed>
     */
    public function scanPdf(mixed $pdf): array
    {
        if (is_array($pdf)) {
            throw new InvalidArgumentException('scanPdf accepts exactly one PDF input; arrays and multiple PDFs are not supported.');
        }

        $maxFileSizeMb = max(1, (int) config('receiptscanner.max_file_size_mb', 20));

        return $this->scan('pdf', [FileInput::pdf($pdf, $maxFileSizeMb)]);
    }

    /**
     * @param array<int, FileInput> $files
     * @return array<string, mixed>
     */
    private function scan(string $inputType, array $files): array
    {
        $startedAt = microtime(true);
        $provider = $this->providerSlug();
        $model = $this->modelForProvider($provider);
        $enabledSections = $this->enabledSections();
        $excludedSections = $this->excludedSections();
        $providerResult = [];

        try {
            $this->validateProviderConfig($provider);

            $context = [
                'prompt' => $this->prompt->build($enabledSections, $inputType),
                'input_type' => $inputType,
                'files' => $files,
                'enabled_sections' => $enabledSections,
                'excluded_sections' => $excludedSections,
                'model' => $model,
                'is_repair' => false,
                'raw_text' => null,
            ];

            $adapter = $this->providerAdapter($provider);
            $providerResult = $adapter->extract($context);
            $decoded = $this->decodeProviderJson($providerResult['text'] ?? null);

            if ($decoded === null) {
                $repairContext = [
                    'prompt' => $this->prompt->repair($enabledSections, $inputType, $this->safeRawText($providerResult['text'] ?? null)),
                    'input_type' => $inputType,
                    'files' => [],
                    'enabled_sections' => $enabledSections,
                    'excluded_sections' => $excludedSections,
                    'model' => $model,
                    'is_repair' => true,
                    'raw_text' => $this->safeRawText($providerResult['text'] ?? null),
                ];

                $providerResult = $adapter->extract($repairContext);
                $decoded = $this->decodeProviderJson($providerResult['text'] ?? null);

                if ($decoded === null) {
                    throw ProviderException::jsonParseFailure('Provider returned invalid JSON after one repair attempt.', [
                        'provider' => $provider,
                        'model' => $model,
                    ]);
                }
            }

            $result = $this->finalizeResult($decoded, $enabledSections, $providerResult, $inputType, count($files));

            $this->logSuccess($provider, $model, $inputType, $files, $enabledSections, $excludedSections, $providerResult, $startedAt);

            return $result;
        } catch (Throwable $exception) {
            $this->logFailure($provider, $model, $inputType, $files, $exception, $startedAt);
            throw $exception;
        }
    }

    private function providerSlug(): string
    {
        $provider = strtolower(trim((string) config('receiptscanner.provider', 'openai')));

        if (! in_array($provider, self::PROVIDERS, true)) {
            throw new ReceiptScannerException(sprintf(
                'Unsupported receipt scanner provider [%s]. Allowed providers are: %s.',
                $provider !== '' ? $provider : '(empty)',
                implode(', ', self::PROVIDERS),
            ));
        }

        return $provider;
    }

    private function providerAdapter(string $provider): object
    {
        return match ($provider) {
            'openai' => $this->openAiProvider,
            'azure_openai' => $this->azureOpenAiProvider,
            'gemini' => $this->geminiProvider,
            'anthropic' => $this->anthropicProvider,
            default => throw new ReceiptScannerException('Unsupported receipt scanner provider.'),
        };
    }

    private function validateProviderConfig(string $provider): void
    {
        $missing = match ($provider) {
            'openai' => $this->missingConfigKeys([
                'receiptscanner.providers.openai.api_key' => 'OPENAI_API_KEY',
            ]),
            'azure_openai' => $this->missingConfigKeys([
                'receiptscanner.providers.azure_openai.api_key' => 'AZURE_OPENAI_API_KEY',
                'receiptscanner.providers.azure_openai.endpoint' => 'AZURE_OPENAI_ENDPOINT',
            ]),
            'gemini' => $this->missingConfigKeys([
                'receiptscanner.providers.gemini.api_key' => 'GEMINI_API_KEY',
            ]),
            'anthropic' => $this->missingConfigKeys([
                'receiptscanner.providers.anthropic.api_key' => 'ANTHROPIC_API_KEY',
                'receiptscanner.providers.anthropic.version' => 'ANTHROPIC_VERSION',
            ]),
            default => ['RECEIPT_SCANNER_PROVIDER'],
        };

        if ($missing !== []) {
            throw new ReceiptScannerException(sprintf(
                'ReceiptScanner provider [%s] is missing required configuration: %s.',
                $provider,
                implode(', ', $missing),
            ));
        }
    }

    /**
     * @param array<string, string> $keys
     * @return array<int, string>
     */
    private function missingConfigKeys(array $keys): array
    {
        $missing = [];

        foreach ($keys as $configKey => $envKey) {
            $value = config($configKey);
            if (! is_scalar($value) || trim((string) $value) === '') {
                $missing[] = $envKey;
            }
        }

        return $missing;
    }

    private function modelForProvider(string $provider): string
    {
        $configured = trim((string) config('receiptscanner.model', ''));
        if ($configured !== '') {
            return $configured;
        }

        if ($provider === 'azure_openai') {
            $deployment = trim((string) config('receiptscanner.providers.azure_openai.deployment', ''));
            if ($deployment !== '') {
                return $deployment;
            }
        }

        $default = trim((string) config("receiptscanner.providers.{$provider}.default_model", ''));

        return $default !== '' ? $default : match ($provider) {
            'openai', 'azure_openai' => 'gpt-5.4-nano',
            'gemini' => 'gemini-3.5-flash',
            'anthropic' => 'claude-sonnet-4-6',
            default => 'unknown',
        };
    }

    /**
     * @return array<int, string>
     */
    private function enabledSections(): array
    {
        $fields = config('receiptscanner.fields', []);
        $excluded = $this->excludedSections();
        $enabled = [];

        foreach (self::DEFAULT_SECTIONS as $section) {
            $isEnabled = true;

            if (is_array($fields) && array_key_exists($section, $fields)) {
                $isEnabled = filter_var($fields[$section], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? (bool) $fields[$section];
            }

            if ($isEnabled && ! in_array($section, $excluded, true)) {
                $enabled[] = $section;
            }
        }

        return $enabled;
    }

    /**
     * @return array<int, string>
     */
    private function excludedSections(): array
    {
        $exclude = config('receiptscanner.exclude', []);

        if (is_string($exclude)) {
            $exclude = explode(',', $exclude);
        }

        if (! is_array($exclude)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $section): string => strtolower(trim((string) $section)),
            $exclude,
        ), static fn (string $section): bool => $section !== '')));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeProviderJson(mixed $text): ?array
    {
        if (! is_scalar($text)) {
            return null;
        }

        $candidate = trim((string) $text);
        if ($candidate === '') {
            return null;
        }

        foreach ($this->jsonCandidates($candidate) as $json) {
            try {
                $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

                if (is_array($decoded) && ! array_is_list($decoded)) {
                    return $decoded;
                }
            } catch (JsonException) {
                // Try the next conservative candidate, then allow the caller to perform one repair request.
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function jsonCandidates(string $text): array
    {
        $candidates = [$text];

        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/is', $text, $matches) === 1) {
            $candidates[] = trim($matches[1]);
        }

        $firstBrace = strpos($text, '{');
        $lastBrace = strrpos($text, '}');

        if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
            $candidates[] = substr($text, $firstBrace, $lastBrace - $firstBrace + 1);
        }

        return array_values(array_unique(array_filter($candidates, static fn (string $candidate): bool => trim($candidate) !== '')));
    }

    /**
     * @param array<string, mixed> $decoded
     * @param array<int, string> $enabledSections
     * @param array<string, mixed> $providerResult
     * @return array<string, mixed>
     */
    private function finalizeResult(array $decoded, array $enabledSections, array $providerResult, string $inputType, int $fileCount): array
    {
        $allowed = array_fill_keys(array_merge(['schema_version'], $enabledSections), true);
        $result = [];

        $result['schema_version'] = isset($decoded['schema_version']) && is_scalar($decoded['schema_version'])
            ? (string) $decoded['schema_version']
            : '1.0';

        foreach ($enabledSections as $section) {
            if (array_key_exists($section, $decoded)) {
                $result[$section] = $decoded[$section];
            }
        }

        if (isset($allowed['metadata'])) {
            $metadata = isset($result['metadata']) && is_array($result['metadata']) ? $result['metadata'] : [];
            $metadata['provider'] = isset($providerResult['provider']) && is_scalar($providerResult['provider']) ? (string) $providerResult['provider'] : null;
            $metadata['model'] = isset($providerResult['model']) && is_scalar($providerResult['model']) ? (string) $providerResult['model'] : null;
            $metadata['input_type'] = $inputType;
            $metadata['image_count'] = $inputType === 'images' ? $fileCount : null;
            $result['metadata'] = $metadata;
        }

        return array_intersect_key($result, $allowed);
    }

    private function safeRawText(mixed $text): string
    {
        if (! is_scalar($text)) {
            return '';
        }

        return (string) $text;
    }

    /**
     * @param array<int, FileInput> $files
     * @param array<int, string> $enabledSections
     * @param array<int, string> $excludedSections
     * @param array<string, mixed> $providerResult
     */
    private function logSuccess(
        string $provider,
        string $model,
        string $inputType,
        array $files,
        array $enabledSections,
        array $excludedSections,
        array $providerResult,
        float $startedAt,
    ): void {
        if (! (bool) config('receiptscanner.logging.enabled', true)) {
            return;
        }

        $this->logger()->log($this->logLevel(), 'ReceiptScanner extraction completed.', [
            'provider' => $provider,
            'model' => $model,
            'input_type' => $inputType,
            'image_count' => $inputType === 'images' ? count($files) : null,
            'mime_types' => $this->fileMimes($files),
            'approximate_file_sizes' => $this->fileSizes($files),
            'enabled_sections' => $enabledSections,
            'excluded_sections' => $excludedSections,
            'duration_ms' => $this->durationMs($startedAt),
            'request_id' => isset($providerResult['request_id']) && is_scalar($providerResult['request_id']) ? (string) $providerResult['request_id'] : null,
            'response_id' => isset($providerResult['response_id']) && is_scalar($providerResult['response_id']) ? (string) $providerResult['response_id'] : null,
        ]);
    }

    /**
     * @param array<int, FileInput> $files
     */
    private function logFailure(string $provider, string $model, string $inputType, array $files, Throwable $exception, float $startedAt): void
    {
        if (! (bool) config('receiptscanner.logging.enabled', true)) {
            return;
        }

        $statusCode = $exception instanceof ProviderException && $exception->getCode() > 0 ? $exception->getCode() : null;

        $this->logger()->error('ReceiptScanner extraction failed.', [
            'provider' => $provider,
            'model' => $model,
            'input_type' => $inputType,
            'image_count' => $inputType === 'images' ? count($files) : null,
            'mime_types' => $this->fileMimes($files),
            'approximate_file_sizes' => $this->fileSizes($files),
            'duration_ms' => $this->durationMs($startedAt),
            'status_code' => $statusCode,
            'error_type' => $this->classBasename($exception::class),
            'message' => $this->truncate($exception->getMessage(), 300),
        ]);
    }

    private function logger(): LoggerInterface
    {
        $channel = config('receiptscanner.logging.channel');
        $channel = is_scalar($channel) ? trim((string) $channel) : '';

        return Log::channel($channel !== '' ? $channel : (string) config('logging.default', 'stack'));
    }

    private function logLevel(): string
    {
        $level = strtolower(trim((string) config('receiptscanner.logging.level', 'info')));

        return in_array($level, ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'], true)
            ? $level
            : 'info';
    }

    private function durationMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    /**
     * @param array<int, FileInput> $files
     * @return array<int, string>
     */
    private function fileMimes(array $files): array
    {
        return array_values(array_map(
            static fn (FileInput $file): string => $file->mime,
            $files,
        ));
    }

    /**
     * @param array<int, FileInput> $files
     * @return array<int, int>
     */
    private function fileSizes(array $files): array
    {
        return array_values(array_map(
            static fn (FileInput $file): int => $file->size,
            $files,
        ));
    }

    private function truncate(string $message, int $limit): string
    {
        if (strlen($message) <= $limit) {
            return $message;
        }

        return substr($message, 0, max(0, $limit - 3)) . '...';
    }

    private function classBasename(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}

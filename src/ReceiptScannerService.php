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
    private const DEFAULT_FIELDS = [
        'merchant',
        'total_amount',
        'currency',
        'date',
        'vat_amount',
        'mcc',
        'vats',
        'line_items',
        'confidence',
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
        $enabledFields = $this->enabledFields();
        $excludedFields = $this->excludedFields();
        $providerResult = [];

        try {
            $this->validateProviderConfig($provider);

            $context = [
                'prompt' => $this->prompt->build($enabledFields, $inputType),
                'input_type' => $inputType,
                'files' => $files,
                'enabled_fields' => $enabledFields,
                'excluded_fields' => $excludedFields,
                'model' => $model,
                'is_repair' => false,
                'raw_text' => null,
            ];

            $adapter = $this->providerAdapter($provider);
            $providerResult = $adapter->extract($context);
            $decoded = $this->decodeProviderJson($providerResult['text'] ?? null);

            if ($decoded === null) {
                $repairContext = [
                    'prompt' => $this->prompt->repair($enabledFields, $inputType, $this->safeRawText($providerResult['text'] ?? null)),
                    'input_type' => $inputType,
                    'files' => [],
                    'enabled_fields' => $enabledFields,
                    'excluded_fields' => $excludedFields,
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

            $result = $this->finalizeResult($decoded, $enabledFields);

            $this->logSuccess($provider, $model, $inputType, $files, $enabledFields, $excludedFields, $providerResult, $startedAt);

            return $result;
        } catch (Throwable $exception) {
            $this->logFailure($provider, $model, $inputType, $files, $exception, $startedAt);
            throw $exception;
        }
    }

    private function providerSlug(): string
    {
        $provider = strtolower(trim((string) config('receiptscanner.provider', config('receiptscanner.default_provider', 'openai'))));

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
            'gemini' => 'gemini-2.5-pro',
            'anthropic' => 'claude-sonnet-4-20250514',
            default => 'unknown',
        };
    }

    /**
     * @return array<int, string>
     */
    private function enabledFields(): array
    {
        $fields = config('receiptscanner.enabled_fields', []);
        $enabled = [];

        foreach (self::DEFAULT_FIELDS as $field) {
            $isEnabled = true;

            if (is_array($fields) && array_key_exists($field, $fields)) {
                $isEnabled = filter_var($fields[$field], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? (bool) $fields[$field];
            }

            if ($isEnabled) {
                $enabled[] = $field;
            }
        }

        return $enabled;
    }

    /**
     * @return array<int, string>
     */
    private function excludedFields(): array
    {
        $exclude = config('receiptscanner.exclude', []);

        if (is_string($exclude)) {
            $exclude = explode(',', $exclude);
        }

        if (! is_array($exclude)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $field): string => strtolower(trim((string) $field)),
            $exclude,
        ), static fn (string $field): bool => $field !== '')));
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
     * @param array<int, string> $enabledFields
     * @return array<string, mixed>
     */
    private function finalizeResult(array $decoded, array $enabledFields): array
    {
        $result = [];

        $canonical = [
            'merchant' => $this->normalizeNullableString($decoded['merchant'] ?? null),
            'total_amount' => $this->normalizeNullableNumber($decoded['total_amount'] ?? $decoded['amount'] ?? null),
            'currency' => $this->normalizeNullableString($decoded['currency'] ?? null),
            'date' => $this->normalizeNullableDate($decoded['date'] ?? null),
            'vat_amount' => $this->normalizeNullableNumber($decoded['vat_amount'] ?? $decoded['tax_amount'] ?? $decoded['vat'] ?? null),
            'mcc' => $this->normalizeNullableString($decoded['mcc'] ?? null),
            'vats' => $this->normalizeVats($decoded['vats'] ?? null, $decoded),
            'line_items' => $this->normalizeLineItems($decoded['line_items'] ?? null),
            'confidence' => $this->normalizeConfidence($decoded['confidence'] ?? null),
        ];

        foreach (self::DEFAULT_FIELDS as $field) {
            if (! in_array($field, $enabledFields, true)) {
                continue;
            }

            $result[$field] = $canonical[$field];
        }

        return $result;
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

    private function normalizeVats(mixed $value, array $decoded = []): array
    {
        $rows = is_array($value) ? $value : [];

        if ($rows === []) {
            $legacyVat = $decoded['vat'] ?? $decoded['tax'] ?? null;
            if (is_array($legacyVat)) {
                $rows = $legacyVat;
            } elseif ($legacyVat !== null || isset($decoded['vat_amount']) || isset($decoded['tax_amount'])) {
                $rows = [[
                    'rate' => $decoded['vat_rate'] ?? $decoded['tax_rate'] ?? null,
                    'amount' => $decoded['vat_amount'] ?? $decoded['tax_amount'] ?? $legacyVat,
                    'amount_inc_vat' => $decoded['total_amount'] ?? $decoded['amount'] ?? null,
                    'amount_ex_vat' => $decoded['amount_excluding_vat'] ?? null,
                ]];
            }
        }

        $normalized = [];
        foreach ($rows as $item) {
            if (! is_array($item)) {
                continue;
            }

            $normalized[] = [
                'rate' => $this->normalizeNullableNumber($item['rate'] ?? $item['vat_rate'] ?? null),
                'amount' => $this->normalizeNullableNumber($item['amount'] ?? $item['vat_amount'] ?? $item['tax_amount'] ?? null),
                'amount_inc_vat' => $this->normalizeNullableNumber($item['amount_inc_vat'] ?? $item['amount_including_vat'] ?? $item['gross_amount'] ?? null),
                'amount_ex_vat' => $this->normalizeNullableNumber($item['amount_ex_vat'] ?? $item['amount_excluding_vat'] ?? $item['net_amount'] ?? null),
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

    private function normalizeConfidence(mixed $value): ?float
    {
        $confidence = $this->normalizeNullableNumber($value);

        if ($confidence === null) {
            return null;
        }

        if ($confidence < 0.0 || $confidence > 1.0) {
            throw new ReceiptScannerException('ReceiptScanner confidence must be between 0 and 1.');
        }

        return $confidence;
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
     * @param array<int, string> $enabledFields
     * @param array<int, string> $excludedFields
     * @param array<string, mixed> $providerResult
     */
    private function logSuccess(
        string $provider,
        string $model,
        string $inputType,
        array $files,
        array $enabledFields,
        array $excludedFields,
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
            'enabled_fields' => $enabledFields,
            'excluded_fields' => $excludedFields,
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

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
        'vats',
        'line_items',
        'payment',
        'confidence',
        'provider',
        'model',
        'raw',
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
            'gemini' => 'gemini-2.5-pro',
            'anthropic' => 'claude-sonnet-4-20250514',
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
        $result = [];

        $result['merchant'] = $this->normalizeMerchant($decoded['merchant'] ?? null);
        $result['receipt'] = $this->normalizeReceipt($decoded['receipt'] ?? null);
        $result['totals'] = $this->normalizeTotals($decoded['totals'] ?? null, $decoded);
        $result['vats'] = $this->normalizeVats($decoded['vats'] ?? null, $decoded);
        $result['line_items'] = $this->normalizeLineItems($decoded['line_items'] ?? null);
        $result['payment'] = $this->normalizePayment($decoded['payment'] ?? null);
        $result['confidence'] = $this->normalizeNullableNumber($decoded['confidence'] ?? null);
        $result['provider'] = isset($providerResult['provider']) && is_scalar($providerResult['provider']) ? (string) $providerResult['provider'] : (string) config('receiptscanner.provider', 'openai');
        $result['model'] = isset($providerResult['model']) && is_scalar($providerResult['model']) ? (string) $providerResult['model'] : $this->modelForProvider($result['provider']);
        $result['raw'] = null;

        if (! in_array('merchant', $enabledSections, true)) {
            unset($result['merchant']);
        }
        if (! in_array('receipt', $enabledSections, true)) {
            unset($result['receipt']);
        }
        if (! in_array('totals', $enabledSections, true)) {
            unset($result['totals']);
        }
        if (! in_array('vats', $enabledSections, true)) {
            unset($result['vats']);
        }
        if (! in_array('line_items', $enabledSections, true)) {
            unset($result['line_items']);
        }
        if (! in_array('payment', $enabledSections, true)) {
            unset($result['payment']);
        }
        if (! in_array('confidence', $enabledSections, true)) {
            unset($result['confidence']);
        }
        if (! in_array('provider', $enabledSections, true)) {
            unset($result['provider']);
        }
        if (! in_array('model', $enabledSections, true)) {
            unset($result['model']);
        }
        if (! in_array('raw', $enabledSections, true)) {
            unset($result['raw']);
        }

        return $result;
    }

    private function normalizeMerchant(mixed $value): array
    {
        $value = is_array($value) ? $value : [];

        return [
            'name' => $this->normalizeNullableString($value['name'] ?? null),
            'organization_number' => $this->normalizeNullableString($value['organization_number'] ?? null),
            'address' => $this->normalizeNullableString($value['address'] ?? null),
        ];
    }

    private function normalizeReceipt(mixed $value): array
    {
        $value = is_array($value) ? $value : [];

        return [
            'receipt_number' => $this->normalizeNullableString($value['receipt_number'] ?? null),
            'purchase_date' => $this->normalizeNullableDate($value['purchase_date'] ?? ($value['date'] ?? null)),
            'purchase_time' => $this->normalizeNullableTime($value['purchase_time'] ?? null),
            'currency' => $this->normalizeNullableString($value['currency'] ?? null),
            'mcc' => $this->normalizeNullableString($value['mcc'] ?? null),
        ];
    }

    private function normalizeTotals(mixed $value, array $decoded = []): array
    {
        $value = is_array($value) ? $value : [];

        return [
            'amount_excluding_vat' => $this->normalizeNullableNumber($value['amount_excluding_vat'] ?? $decoded['amount_excluding_vat'] ?? null),
            'vat_amount' => $this->normalizeNullableNumber($value['vat_amount'] ?? $decoded['vat_amount'] ?? $decoded['tax_amount'] ?? null),
            'amount_including_vat' => $this->normalizeNullableNumber($value['amount_including_vat'] ?? ($value['amount'] ?? ($decoded['amount'] ?? $decoded['total'] ?? null))),
            'rounding' => $this->normalizeNullableNumber($value['rounding'] ?? null),
        ];
    }

    private function normalizeVats(mixed $value, array $decoded = []): array
    {
        $rows = is_array($value) ? $value : [];

        if ($rows === []) {
            $legacyVat = $decoded['vat'] ?? $decoded['tax'] ?? null;
            if (is_array($legacyVat)) {
                $rows = $legacyVat;
            } elseif ($legacyVat !== null) {
                $rows = [[
                    'vat_rate' => $decoded['vat_rate'] ?? $decoded['tax_rate'] ?? null,
                    'amount_excluding_vat' => $decoded['amount_excluding_vat'] ?? null,
                    'vat_amount' => $legacyVat,
                    'amount_including_vat' => $decoded['amount_including_vat'] ?? ($decoded['amount'] ?? null),
                ]];
            }
        }

        $normalized = [];
        foreach ($rows as $item) {
            if (! is_array($item)) {
                continue;
            }

            $normalized[] = [
                'vat_rate' => $this->normalizeNullableNumber($item['vat_rate'] ?? $item['rate'] ?? null),
                'amount_excluding_vat' => $this->normalizeNullableNumber($item['amount_excluding_vat'] ?? $item['net_amount'] ?? null),
                'vat_amount' => $this->normalizeNullableNumber($item['vat_amount'] ?? $item['tax_amount'] ?? $item['vat'] ?? $item['tax'] ?? null),
                'amount_including_vat' => $this->normalizeNullableNumber($item['amount_including_vat'] ?? $item['gross_amount'] ?? null),
            ];
        }

        return $normalized;
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
                'amount_including_vat' => $this->normalizeNullableNumber($item['amount_including_vat'] ?? ($item['amount'] ?? null)),
                'vat_rate' => $this->normalizeNullableNumber($item['vat_rate'] ?? null),
                'category' => $this->normalizeNullableString($item['category'] ?? null),
            ];
        }

        return $normalized;
    }

    private function normalizePayment(mixed $value): array
    {
        $value = is_array($value) ? $value : [];

        return [
            'method' => $this->normalizeNullableString($value['method'] ?? null),
            'card_last4' => $this->normalizeNullableString($value['card_last4'] ?? null),
        ];
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

    private function normalizeNullableTime(mixed $value): ?string
    {
        $value = $this->normalizeNullableString($value);
        if ($value === null) {
            return null;
        }

        return preg_match('/^\d{2}:\d{2}$/', $value) === 1 ? $value : null;
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

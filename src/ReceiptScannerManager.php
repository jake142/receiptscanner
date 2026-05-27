<?php

declare(strict_types=1);

namespace Jake142\ReceiptScanner;

use Jake142\ReceiptScanner\Providers\AzureOpenAiProvider;
use Jake142\ReceiptScanner\Providers\OpenAiProvider;
use RuntimeException;

class ReceiptScannerManager
{
    public function __construct(
        private readonly OpenAiProvider $openAiProvider,
        private readonly AzureOpenAiProvider $azureOpenAiProvider,
    ) {
    }

    /**
     * Scan a receipt image or PDF and return structured receipt data.
     *
     * @param string $pathOrContents
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function scan(string $pathOrContents, array $options = []): array
    {
        $context = $this->buildProviderContext($pathOrContents, $options);
        $provider = $this->resolveProvider($options);

        return $provider->extract($context);
    }

    /**
     * @param string $pathOrContents
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function buildProviderContext(string $pathOrContents, array $options): array
    {
        $context = [
            'path' => $pathOrContents,
            'options' => $options,
        ];

        if (is_file($pathOrContents) && is_readable($pathOrContents)) {
            $contents = file_get_contents($pathOrContents);

            if ($contents !== false) {
                $context['contents'] = $contents;
            }
        }

        return $context;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function resolveProvider(array $options): OpenAiProvider|AzureOpenAiProvider
    {
        $provider = $options['provider'] ?? config('receiptscanner.provider', config('receiptscanner.default', 'openai'));

        if (! is_string($provider)) {
            throw new RuntimeException('ReceiptScanner provider is invalid.');
        }

        return match (strtolower(trim($provider))) {
            'azure', 'azure_openai', 'azure-openai' => $this->azureOpenAiProvider,
            'openai' => $this->openAiProvider,
            default => throw new RuntimeException(sprintf('Unsupported ReceiptScanner provider [%s].', $provider)),
        };
    }
}

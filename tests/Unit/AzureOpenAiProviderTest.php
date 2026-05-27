<?php

declare(strict_types=1);

namespace Tests\Unit;

use Jake142\ReceiptScanner\ReceiptScannerManager;
use Jake142\ReceiptScanner\Tests\TestCase;

class AzureOpenAiProviderTest extends TestCase
{
    public function test_container_resolves_manager_and_uses_configured_provider_key(): void
    {
        config()->set('receiptscanner.default_provider', 'azure_openai');
        config()->set('receiptscanner.provider', 'azure_openai');
        config()->set('receiptscanner.providers.azure_openai.endpoint', 'https://example-resource.openai.azure.com');
        config()->set('receiptscanner.providers.azure_openai.api_key', 'test-key');
        config()->set('receiptscanner.providers.azure_openai.deployment', 'gpt-5.4-nano');

        $manager = $this->app->make(ReceiptScannerManager::class);

        $this->assertInstanceOf(ReceiptScannerManager::class, $manager);
        $this->assertSame('azure_openai', config('receiptscanner.provider'));
        $this->assertSame('azure_openai', config('receiptscanner.provider'));
        $this->assertSame('gpt-5.4-nano', config('receiptscanner.providers.azure_openai.deployment'));
    }
}

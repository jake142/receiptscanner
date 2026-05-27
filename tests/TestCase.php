<?php

declare(strict_types=1);

namespace Jake142\ReceiptScanner\Tests;

use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;
use Jake142\ReceiptScanner\ReceiptScannerServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [ReceiptScannerServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('receiptscanner.api_key', 'test-api-key');
        $app['config']->set('receiptscanner.base_url', 'https://api.example.test');
        $app['config']->set('receiptscanner.providers.openai.api_key', 'test-api-key');
        $app['config']->set('receiptscanner.api_keys.openai', 'test-api-key');
        $app['config']->set('receiptscanner.providers.azure_openai.api_key', 'test-api-key');
        $app['config']->set('receiptscanner.api_keys.azure_openai', 'test-api-key');
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }
}

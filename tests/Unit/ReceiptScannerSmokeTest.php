<?php

declare(strict_types=1);

namespace Jake142\ReceiptScanner\Tests\Unit;

use Jake142\ReceiptScanner\ReceiptScanner;
use Jake142\ReceiptScanner\ReceiptScannerServiceProvider;
use Orchestra\Testbench\TestCase;

class ReceiptScannerSmokeTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            ReceiptScannerServiceProvider::class,
        ];
    }

    public function test_package_binds_the_scanner_service(): void
    {
        $scanner = $this->app->make(ReceiptScanner::class);

        $this->assertInstanceOf(ReceiptScanner::class, $scanner);
    }

    public function test_config_exposes_the_default_provider_key(): void
    {
        $this->assertSame('openai', config('receiptscanner.default_provider'));
    }
}

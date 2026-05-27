<?php

declare(strict_types=1);

namespace Jake142\ReceiptScanner\Tests\Unit;

use Jake142\ReceiptScanner\ReceiptScannerManager;
use Jake142\ReceiptScanner\Tests\TestCase;

class ReceiptScannerSmokeTest extends TestCase
{
    public function test_container_resolves_receipt_scanner_manager(): void
    {
        $instance = $this->app->make(ReceiptScannerManager::class);

        $this->assertInstanceOf(ReceiptScannerManager::class, $instance);
    }

    public function test_config_exposes_default_provider_key(): void
    {
        $this->assertSame('openai', config('receiptscanner.provider'));
    }
}

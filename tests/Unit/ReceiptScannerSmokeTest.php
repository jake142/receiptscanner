<?php

declare(strict_types=1);

namespace Jake142\ReceiptScanner\Tests\Unit;

use Jake142\ReceiptScanner\ReceiptScannerManager;
use Jake142\ReceiptScanner\Tests\TestCase;

class ReceiptScannerSmokeTest extends TestCase
{
    public function test_package_boots(): void
    {
        $this->assertTrue(app()->bound(ReceiptScannerManager::class));
        $this->assertNotNull(config('receiptscanner.default_provider'));
    }
}

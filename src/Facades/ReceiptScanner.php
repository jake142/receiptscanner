<?php

declare(strict_types=1);

namespace Jake142\ReceiptScanner\Facades;

use Illuminate\Support\Facades\Facade;

class ReceiptScanner extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Jake142\ReceiptScanner\ReceiptScannerManager::class;
    }
}

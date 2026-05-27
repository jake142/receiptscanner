<?php

namespace Jake142\ReceiptScanner\Facades;

use Illuminate\Support\Facades\Facade;

class ReceiptScanner extends Facade
{
    /**
     * Get the registered manager binding name.
     */
    protected static function getFacadeAccessor(): string
    {
        return \Jake142\ReceiptScanner\ReceiptScannerManager::class;
    }
}

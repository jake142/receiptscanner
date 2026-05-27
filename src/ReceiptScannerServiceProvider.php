<?php

declare(strict_types=1);

namespace Jake142\ReceiptScanner;

use Illuminate\Support\ServiceProvider;
use Jake142\ReceiptScanner\Providers\AzureOpenAiProvider;
use Jake142\ReceiptScanner\Providers\OpenAiProvider;

class ReceiptScannerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/receiptscanner.php', 'receiptscanner');

        $this->app->singleton(OpenAiProvider::class);
        $this->app->singleton(AzureOpenAiProvider::class);
        $this->app->singleton(ReceiptScannerManager::class, function ($app): ReceiptScannerManager {
            return new ReceiptScannerManager(
                $app->make(OpenAiProvider::class),
                $app->make(AzureOpenAiProvider::class),
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/receiptscanner.php' => config_path('receiptscanner.php'),
        ], 'receiptscanner-config');
    }
}

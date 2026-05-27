<?php

declare(strict_types=1);

namespace Jake142\ReceiptScanner;

use Illuminate\Support\ServiceProvider;
use Jake142\ReceiptScanner\Facades\ReceiptScanner as ReceiptScannerFacade;
use Jake142\ReceiptScanner\Providers\AnthropicProvider;
use Jake142\ReceiptScanner\Providers\AzureOpenAiProvider;
use Jake142\ReceiptScanner\Providers\GeminiProvider;
use Jake142\ReceiptScanner\Providers\OpenAiProvider;

class ReceiptScannerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/receiptscanner.php', 'receiptscanner');

        $this->app->singleton(OpenAiProvider::class, static fn (): OpenAiProvider => new OpenAiProvider());
        $this->app->singleton(AzureOpenAiProvider::class, static fn (): AzureOpenAiProvider => new AzureOpenAiProvider());
        $this->app->singleton(GeminiProvider::class, static fn (): GeminiProvider => new GeminiProvider());
        $this->app->singleton(AnthropicProvider::class, static fn (): AnthropicProvider => new AnthropicProvider());

        $this->app->singleton(ReceiptScannerManager::class, function ($app): ReceiptScannerManager {
            return new ReceiptScannerManager(
                $app->make(OpenAiProvider::class),
                $app->make(AzureOpenAiProvider::class),
                $app->make(GeminiProvider::class),
                $app->make(AnthropicProvider::class),
            );
        });

        $this->app->alias(ReceiptScannerManager::class, 'receiptscanner');
        $this->app->alias(ReceiptScannerManager::class, ReceiptScannerFacade::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/receiptscanner.php' => config_path('receiptscanner.php'),
        ], 'receiptscanner-config');
    }
}

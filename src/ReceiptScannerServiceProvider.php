<?php

declare(strict_types=1);

namespace Jake142\ReceiptScanner;

use Illuminate\Support\ServiceProvider;
use Jake142\ReceiptScanner\Prompt\ReceiptPrompt;
use Jake142\ReceiptScanner\Providers\AnthropicProvider;
use Jake142\ReceiptScanner\Providers\AzureOpenAiProvider;
use Jake142\ReceiptScanner\Providers\GeminiProvider;
use Jake142\ReceiptScanner\Providers\OpenAiProvider;

class ReceiptScannerServiceProvider extends ServiceProvider
{
    private const CONFIG_KEY = 'receiptscanner';

    public function register(): void
    {
        $this->mergeConfigFrom($this->configPath(), self::CONFIG_KEY);

        $this->app->singleton(ReceiptPrompt::class, static fn (): ReceiptPrompt => new ReceiptPrompt());

        $this->app->singleton(OpenAiProvider::class, static fn (): OpenAiProvider => new OpenAiProvider());
        $this->app->singleton(AzureOpenAiProvider::class, static fn (): AzureOpenAiProvider => new AzureOpenAiProvider());
        $this->app->singleton(GeminiProvider::class, static fn (): GeminiProvider => new GeminiProvider());
        $this->app->singleton(AnthropicProvider::class, static fn (): AnthropicProvider => new AnthropicProvider());

        $this->app->singleton(ReceiptScannerService::class, static fn ($app): ReceiptScannerService => new ReceiptScannerService(
            $app->make(ReceiptPrompt::class),
            $app->make(OpenAiProvider::class),
            $app->make(AzureOpenAiProvider::class),
            $app->make(GeminiProvider::class),
            $app->make(AnthropicProvider::class),
        ));

        $this->app->singleton(ReceiptScannerManager::class, static fn ($app): ReceiptScannerManager => new ReceiptScannerManager(
            $app->make(ReceiptScannerService::class),
        ));

        $this->app->alias(ReceiptScannerManager::class, self::CONFIG_KEY);
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            $this->configPath() => config_path('receiptscanner.php'),
        ], 'receiptscanner-config');
    }

    /**
     * @return array<int, string>
     */
    public function provides(): array
    {
        return [
            ReceiptPrompt::class,
            OpenAiProvider::class,
            AzureOpenAiProvider::class,
            GeminiProvider::class,
            AnthropicProvider::class,
            ReceiptScannerService::class,
            ReceiptScannerManager::class,
            self::CONFIG_KEY,
        ];
    }

    private function configPath(): string
    {
        return __DIR__ . '/../config/receiptscanner.php';
    }
}

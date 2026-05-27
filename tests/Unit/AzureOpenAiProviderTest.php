<?php

declare(strict_types=1);

namespace Jake142\ReceiptScanner\Tests\Unit;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Jake142\ReceiptScanner\Providers\AzureOpenAiProvider;
use Jake142\ReceiptScanner\Tests\TestCase;

class AzureOpenAiProviderTest extends TestCase
{
    public function test_unset_azure_api_version_uses_v1_responses_url_without_api_version_query(): void
    {
        $this->scanWithAzureApiVersion(null);

        Http::assertSent(function (Request $request): bool {
            $url = $request->url();

            return parse_url($url, PHP_URL_PATH) === '/openai/v1/responses'
                && parse_url($url, PHP_URL_QUERY) === null
                && ! str_contains($url, 'api-version');
        });
    }

    public function test_empty_azure_api_version_uses_v1_responses_url_without_api_version_query(): void
    {
        $this->scanWithAzureApiVersion('');

        Http::assertSent(function (Request $request): bool {
            $url = $request->url();

            return parse_url($url, PHP_URL_PATH) === '/openai/v1/responses'
                && parse_url($url, PHP_URL_QUERY) === null
                && ! str_contains($url, 'api-version');
        });
    }

    public function test_v1_azure_api_version_uses_v1_responses_url_without_api_version_query(): void
    {
        $this->scanWithAzureApiVersion('v1');

        Http::assertSent(function (Request $request): bool {
            $url = $request->url();

            return parse_url($url, PHP_URL_PATH) === '/openai/v1/responses'
                && parse_url($url, PHP_URL_QUERY) === null
                && ! str_contains($url, 'api-version');
        });
    }

    public function test_preview_azure_api_version_uses_legacy_responses_url_with_encoded_api_version_query(): void
    {
        $this->scanWithAzureApiVersion('2025-03-01-preview');

        Http::assertSent(function (Request $request): bool {
            $url = $request->url();

            return parse_url($url, PHP_URL_PATH) === '/openai/responses'
                && parse_url($url, PHP_URL_QUERY) === 'api-version=2025-03-01-preview'
                && ! str_contains($url, '/openai/v1/responses');
        });
    }

    private function scanWithAzureApiVersion(?string $apiVersion): void
    {
        config()->set('receiptscanner.providers.azure_openai.endpoint', 'https://receipt-scanner-resource.openai.azure.com/');
        config()->set('receiptscanner.providers.azure_openai.api_key', 'test-azure-api-key');
        config()->set('receiptscanner.providers.azure_openai.deployment', 'openai-5.4-nano');
        config()->set('receiptscanner.providers.azure_openai.api_version', $apiVersion);
        config()->set('receiptscanner.retries', 0);

        Http::fake([
            '*' => Http::response([
                'parsed' => [
                    'merchant' => 'Test Merchant',
                ],
            ], 200),
        ]);

        $path = tempnam(sys_get_temp_dir(), 'receipt-scanner-azure-test-');
        $this->assertIsString($path);
        file_put_contents($path, 'fake image bytes');

        try {
            $provider = new AzureOpenAiProvider();
            $provider->extract([
                'paths' => [$path],
                'fields' => ['merchant'],
                'options' => [
                    'mime_type' => 'image/jpeg',
                    'retries' => 0,
                ],
            ]);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }

        Http::assertSentCount(1);
    }
}

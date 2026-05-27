<?php

declare(strict_types=1);

namespace Tests\Unit;

use Jake142\ReceiptScanner\Providers\AzureOpenAiProvider;
use Orchestra\Testbench\TestCase;

class AzureOpenAiProviderTest extends TestCase
{
    public function test_it_uses_the_configured_azure_openai_deployment_and_v1_responses_endpoint(): void
    {
        config()->set('receiptscanner.providers.azure_openai.endpoint', 'https://example-resource.openai.azure.com/');
        config()->set('receiptscanner.providers.azure_openai.api_key', 'test-key');
        config()->set('receiptscanner.providers.azure_openai.deployment', 'gpt-5.4-nano');
        config()->set('receiptscanner.providers.azure_openai.api_version', 'v1');

        $provider = new class extends AzureOpenAiProvider {
            public array $captured = [];

            protected function send(string $url, string $apiKey, array $payload, string $mode, array $context): object
            {
                $this->captured = compact('url', 'apiKey', 'payload', 'mode', 'context');

                return new class {
                    public function successful(): bool
                    {
                        return true;
                    }

                    public function json(): array
                    {
                        return [
                            'parsed' => [
                                'merchant' => 'Coffee Shop',
                                'date' => '2025-05-27',
                                'amount' => 18.4,
                                'currency' => 'SEK',
                                'vat_amount' => 3.68,
                                'line_items' => [],
                                'mcc' => '5814',
                            ],
                        ];
                    }

                    public function status(): int
                    {
                        return 200;
                    }

                    public function body(): string
                    {
                        return '{}';
                    }

                    public function header(string $name): ?string
                    {
                        return null;
                    }
                };
            }
        };

        $result = $provider->extract([
            'paths' => ['/tmp/receipt.pdf'],
            'input_type' => 'pdf',
            'fields' => ['merchant', 'date', 'amount', 'currency', 'vat_amount', 'line_items', 'mcc'],
        ]);

        $this->assertSame('https://example-resource.openai.azure.com/openai/v1/responses', $provider->captured['url']);
        $this->assertSame('test-key', $provider->captured['apiKey']);
        $this->assertSame('gpt-5.4-nano', $provider->captured['payload']['model']);
        $this->assertSame('v1', $provider->captured['mode']);
        $this->assertSame('Coffee Shop', $result['merchant']);
        $this->assertSame(18.4, $result['amount']);
        $this->assertSame('SEK', $result['currency']);
    }

    public function test_it_builds_legacy_endpoint_when_api_version_is_not_v1(): void
    {
        config()->set('receiptscanner.providers.azure_openai.endpoint', 'https://example-resource.openai.azure.com');
        config()->set('receiptscanner.providers.azure_openai.api_key', 'test-key');
        config()->set('receiptscanner.providers.azure_openai.deployment', 'gpt-5.4-nano');
        config()->set('receiptscanner.providers.azure_openai.api_version', '2024-10-21');

        $provider = new class extends AzureOpenAiProvider {
            public array $captured = [];

            protected function send(string $url, string $apiKey, array $payload, string $mode, array $context): object
            {
                $this->captured = compact('url', 'apiKey', 'payload', 'mode', 'context');

                return new class {
                    public function successful(): bool
                    {
                        return true;
                    }

                    public function json(): array
                    {
                        return ['parsed' => []];
                    }

                    public function status(): int
                    {
                        return 200;
                    }

                    public function body(): string
                    {
                        return '{}';
                    }

                    public function header(string $name): ?string
                    {
                        return null;
                    }
                };
            }
        };

        $provider->extract([
            'paths' => ['/tmp/receipt.jpg'],
            'input_type' => 'images',
            'fields' => ['merchant'],
        ]);

        $this->assertSame('https://example-resource.openai.azure.com/openai/responses?api-version=2024-10-21', $provider->captured['url']);
        $this->assertSame('legacy', $provider->captured['mode']);
    }

    protected function getPackageProviders($app): array
    {
        return [];
    }
}

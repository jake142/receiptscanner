<?php

declare(strict_types=1);

namespace Jake142\ReceiptScanner\Tests\Unit;

use Illuminate\Support\Facades\Http;
use Jake142\ReceiptScanner\Providers\AzureOpenAiProvider;
use Jake142\ReceiptScanner\Tests\TestCase;
use RuntimeException;

class AzureOpenAiProviderHttpErrorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('receiptscanner.providers.azure_openai', [
            'endpoint' => 'https://example.openai.azure.com',
            'api_key' => 'test-key',
            'deployment' => 'gpt-test',
            'api_version' => null,
        ]);
    }

    public function test_http_failure_surfaces_provider_message_without_undefined_method_error(): void
    {
        Http::fake([
            'https://example.openai.azure.com/openai/v1/responses' => Http::response([
                'error' => ['message' => 'Deployment not found'],
            ], 404, ['apim-request-id' => 'req-123']),
        ]);

        $provider = new AzureOpenAiProvider();

        try {
            $provider->extract([
                'paths' => [$this->temporaryReceiptPath()],
                'options' => ['retries' => 0],
            ]);

            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('HTTP 404', $exception->getMessage());
            $this->assertStringContainsString('Deployment not found', $exception->getMessage());
            $this->assertStringNotContainsString('undefined method', $exception->getMessage());
        }
    }

    private function temporaryReceiptPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'receiptscanner-');

        if ($path === false) {
            $this->fail('Could not create temporary receipt file.');
        }

        $jpgPath = $path.'.jpg';
        rename($path, $jpgPath);
        file_put_contents($jpgPath, base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDAREAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAn/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCwAA8A/9k='));

        return $jpgPath;
    }
}

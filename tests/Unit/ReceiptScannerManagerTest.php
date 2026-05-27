<?php

declare(strict_types=1);

namespace Jake142\ReceiptScanner\Tests\Unit;

use Jake142\ReceiptScanner\Prompt\ReceiptPrompt;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ReceiptScannerManagerTest extends TestCase
{
    #[Test]
    public function it_normalizes_legacy_tax_and_vat_fields_into_canonical_vats_shape(): void
    {
        $prompt = new ReceiptPrompt();

        $schema = $prompt->expectedJson(['merchant', 'receipt', 'totals', 'vats', 'line_items']);

        $this->assertArrayHasKey('vats', $schema);
        $this->assertIsArray($schema['vats']);
        $this->assertArrayHasKey('vat_rate', $schema['vats'][0]);
        $this->assertArrayHasKey('vat_amount', $schema['vats'][0]);
        $this->assertArrayHasKey('amount_excluding_vat', $schema['vats'][0]);
        $this->assertArrayHasKey('amount_including_vat', $schema['vats'][0]);
    }

    #[Test]
    public function it_builds_a_prompt_that_requests_vats_instead_of_legacy_tax_fields(): void
    {
        $prompt = new ReceiptPrompt();

        $text = $prompt->build(['merchant' => true, 'receipt' => true, 'totals' => true, 'vats' => true], 'images');

        $this->assertStringContainsString('vats: array<object>', $text);
        $this->assertStringNotContainsString('tax must', $text);
        $this->assertStringNotContainsString('vat as a string', $text);
    }

    #[Test]
    public function it_supports_list_and_boolean_field_syntax_via_prompt_normalization(): void
    {
        $prompt = new ReceiptPrompt();

        $listSchema = $prompt->expectedJson(['merchant', 'date', 'amount', 'vats']);
        $boolSchema = $prompt->expectedJson([
            'merchant' => true,
            'receipt' => true,
            'totals' => true,
            'vats' => true,
            'line_items' => false,
        ]);

        $this->assertArrayHasKey('merchant', $listSchema);
        $this->assertArrayHasKey('vats', $listSchema);
        $this->assertArrayHasKey('merchant', $boolSchema);
        $this->assertArrayHasKey('vats', $boolSchema);
        $this->assertArrayNotHasKey('line_items', $boolSchema);
    }
}

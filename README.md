# ReceiptScanner

ReceiptScanner is a small Laravel package for extracting structured receipt data from images and PDFs using upstream AI providers.

It is a wrapper around multimodal LLM APIs. It does not host its own OCR service, database, queue, UI, or REST API.

## Features

- Laravel facade entry point: `Jake142\ReceiptScanner\Facades\ReceiptScanner`
- Public methods:
  - `ReceiptScanner::scanImages(array $images): array`
  - `ReceiptScanner::scanPdf(mixed $pdf): array`
- Supports multi-image receipt analysis in a single upstream request
- Supports PDF receipt analysis from one input file
- Provider selection via config/env
- Default provider models:
  - OpenAI: `gpt-5`
  - Azure OpenAI: `gpt-5`
  - Gemini: `gemini-2.5-pro`
  - Anthropic: `claude-sonnet-4-20250514`
- Configurable output fields to reduce prompt size and response size
- Safe logging controls

## Requirements

- PHP `^8.3`
- Laravel `illuminate/support` `^11.0|^12.0|^13.0`
- Laravel `illuminate/http` `^11.0|^12.0|^13.0`
- For development and testing:
  - `orchestra/testbench` `^9.0|^10.0|^11.0`

## Installation

```bash
composer require jake142/receiptscanner
```

Publish the config file:

```bash
php artisan vendor:publish --tag=receiptscanner-config
```

## Configuration

ReceiptScanner is configured through `config/receiptscanner.php` and environment variables.

### Environment variables

```env
RECEIPT_SCANNER_PROVIDER=openai
RECEIPT_SCANNER_MODEL=
RECEIPT_SCANNER_TIMEOUT=60
RECEIPT_SCANNER_RETRIES=2
RECEIPT_SCANNER_LOGGING=false
RECEIPT_SCANNER_INCLUDE_VATS=true
RECEIPT_SCANNER_MAX_FILE_SIZE_MB=32

OPENAI_API_KEY=
OPENAI_MODEL=gpt-5

AZURE_OPENAI_ENDPOINT=
AZURE_OPENAI_API_KEY=
AZURE_OPENAI_DEPLOYMENT=gpt-5
AZURE_OPENAI_MODEL=gpt-5

GEMINI_API_KEY=
GEMINI_MODEL=gemini-2.5-pro

ANTHROPIC_API_KEY=
ANTHROPIC_MODEL=claude-sonnet-4-20250514
```

### Config shape

The package config is expected to expose these keys:

- `default_provider`
- `provider`
- `model`
- `timeout`
- `retries`
- `logging`
- `include_vats`
- `max_file_size_mb`
- `enabled_fields`
- `providers.openai.api_key`
- `providers.openai.model`
- `providers.azure_openai.endpoint`
- `providers.azure_openai.api_key`
- `providers.azure_openai.deployment`
- `providers.azure_openai.model`
- `providers.gemini.api_key`
- `providers.gemini.model`
- `providers.anthropic.api_key`
- `providers.anthropic.model`

### Default output fields

By default, all fields are enabled:

- `merchant`
- `date`
- `amount`
- `currency`
- `vat_amount`
- `line_items`
- `mcc`
- `vats`

You can disable parts of the response in config to reduce prompt size and token usage. For example, if you do not need VAT breakdowns, set `include_vats` to `false` and remove `vats` from `enabled_fields`.

## Usage

### Scan multiple images

Use `scanImages()` when a receipt is split across several photos.

```php
use Jake142\ReceiptScanner\Facades\ReceiptScanner;

$result = ReceiptScanner::scanImages([
    storage_path('app/receipts/receipt-part-1.jpg'),
    storage_path('app/receipts/receipt-part-2.jpg'),
]);
```

The images are analyzed together as one receipt. This is useful when the top and bottom of a long receipt were captured in separate photos.

### Scan a PDF

Use `scanPdf()` for a single PDF input.

```php
use Jake142\ReceiptScanner\Facades\ReceiptScanner;

$result = ReceiptScanner::scanPdf(storage_path('app/receipts/receipt.pdf'));
```

## Result format

`$result` is a JSON-compatible associative array. The package asks the upstream model to return strict JSON only.

Default output shape:

```php
[
    'merchant' => 'Coffee Shop',
    'date' => '2025-05-27',
    'amount' => 18.40,
    'currency' => 'SEK',
    'vat_amount' => 3.68,
    'line_items' => [
        [
            'description' => 'Latte',
            'quantity' => 1,
            'unit_price' => 42.00,
            'amount' => 42.00,
        ],
    ],
    'mcc' => '5814',
    'vats' => [
        [
            'vat_amount' => 3.68,
            'vat_rate' => 0.25,
            'amount_including_vat' => 18.40,
            'amount_excluding_vat' => 14.72,
        ],
    ],
]
```

Notes:

- `tax` is not used anywhere in the output.
- Unknown scalar values are returned as `null`.
- Unknown arrays are returned as `[]`.
- Dates are normalized to `YYYY-MM-DD` when possible.
- Numeric values are normalized to numbers, not strings, when possible.
- `mcc` is AI-estimated because receipts usually do not contain MCC directly.

## Provider selection

Set the provider and model in env, then load them through config.

Example:

```env
RECEIPT_SCANNER_PROVIDER=openai
RECEIPT_SCANNER_MODEL=gpt-5
```

Azure OpenAI is supported as well. When using Azure OpenAI, configure the Azure provider settings and use the OpenAI `gpt-5` model/deployment.

## Logging

If logging is enabled, the package may log safe diagnostics such as provider, model, mime type, input size, retry count, duration, and upstream request id/status when available.

It will not log API keys, raw receipt contents, or full base64 payloads.

## Testing

The package is designed to be tested with Orchestra Testbench and PHPUnit.

External provider calls should be mocked in tests.

## Disclaimer

ReceiptScanner uses upstream AI providers to interpret receipts. It does not guarantee accounting accuracy. Always review critical financial data before using it in production workflows.

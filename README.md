# ReceiptScanner

ReceiptScanner is a small Laravel package for extracting structured receipt data from images and PDFs using upstream AI providers.

It is a wrapper around multimodal LLM APIs. It does not host its own OCR service, database, queue, UI, or REST API.

The package i created by Packr.

## Features

- Laravel facade entry point: `Jake142\ReceiptScanner\Facades\ReceiptScanner`
- Public methods:
  - `ReceiptScanner::scanImages(array $images): array`
  - `ReceiptScanner::scanPdf(mixed $pdf): array`
- Supports multi-image receipt analysis in a single upstream request
- Supports PDF receipt analysis from one input file
- Provider selection via config/env
- Default provider models:
  - OpenAI: `gpt-5.4-nano`
  - Azure OpenAI: `gpt-5.4-nano`
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
RECEIPTSCANNER_PROVIDER=openai
RECEIPTSCANNER_MODEL=
RECEIPTSCANNER_TIMEOUT=60
RECEIPTSCANNER_RETRIES=2
RECEIPTSCANNER_MAX_FILE_SIZE_MB=32
RECEIPTSCANNER_INCLUDE_VATS=true
RECEIPTSCANNER_LOGGING=false

OPENAI_API_KEY=
OPENAI_MODEL=gpt-5.4-nano
OPENAI_BASE_URL=https://api.openai.com/v1

AZURE_OPENAI_ENDPOINT=
AZURE_OPENAI_API_KEY=
AZURE_OPENAI_DEPLOYMENT=gpt-5.4-nano
AZURE_OPENAI_API_VERSION=
AZURE_OPENAI_ENDPOINT_MODE=v1

GEMINI_API_KEY=
GEMINI_MODEL=gemini-2.5-pro
GEMINI_BASE_URL=https://generativelanguage.googleapis.com/v1beta

ANTHROPIC_API_KEY=
ANTHROPIC_MODEL=claude-sonnet-4-20250514
ANTHROPIC_BASE_URL=https://api.anthropic.com/v1
```

### Config shape

The package config is expected to expose these keys:

- `default_provider`
- `providers.openai.api_key`
- `providers.openai.model`
- `providers.openai.base_url`
- `providers.azure_openai.endpoint`
- `providers.azure_openai.api_key`
- `providers.azure_openai.deployment`
- `providers.azure_openai.api_version`
- `providers.azure_openai.endpoint_mode`
- `providers.gemini.api_key`
- `providers.gemini.model`
- `providers.gemini.base_url`
- `providers.anthropic.api_key`
- `providers.anthropic.model`
- `providers.anthropic.base_url`
- `timeout`
- `retries`
- `max_file_size_mb`
- `fields`
- `include_vats`
- `logging`

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

You can disable parts of the response in config to reduce prompt size and response size. For example, if you do not need VAT breakdowns, set `include_vats` to `false` and remove `vats` from `fields`.

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
    'merchant' => [
        'name' => 'Coffee Shop',
        'organization_number' => null,
        'address' => null,
    ],
    'receipt' => [
        'receipt_number' => null,
        'purchase_date' => '2025-05-27',
        'purchase_time' => null,
        'currency' => 'SEK',
        'mcc' => '5814',
    ],
    'totals' => [
        'amount_excluding_vat' => 14.72,
        'vat_amount' => 3.68,
        'amount_including_vat' => 18.40,
        'rounding' => null,
    ],
    'vats' => [
        [
            'vat_rate' => 25,
            'amount_excluding_vat' => 14.72,
            'vat_amount' => 3.68,
            'amount_including_vat' => 18.40,
        ],
    ],
    'line_items' => [
        [
            'description' => 'Latte',
            'quantity' => 1,
            'unit_price' => 42.00,
            'amount_including_vat' => 42.00,
            'vat_rate' => 25,
            'category' => null,
        ],
    ],
    'payment' => [
        'method' => 'card',
        'card_last4' => null,
    ],
    'confidence' => 0.93,
    'provider' => 'openai',
    'model' => 'gpt-5.4-nano',
    'raw' => null,
]
```

VAT array example:

```php
'vats' => [
    [
        'vat_rate' => 25,
        'amount_excluding_vat' => 1000,
        'vat_amount' => 250,
        'amount_including_vat' => 1250,
    ],
    [
        'vat_rate' => 12,
        'amount_excluding_vat' => 500,
        'vat_amount' => 60,
        'amount_including_vat' => 560,
    ],
    [
        'vat_rate' => 6,
        'amount_excluding_vat' => 200,
        'vat_amount' => 12,
        'amount_including_vat' => 212,
    ],
]
```

Notes:

- `vats` is always an array.
- `vats` must never be a string such as `"12%"` or `"25%"`.
- Unknown scalar values are returned as `null`.
- Unknown arrays are returned as `[]`.
- Dates are normalized to `YYYY-MM-DD` when possible.
- Times are normalized to `HH:MM` when possible.
- Numeric values are normalized to numbers, not strings, when possible.
- `mcc` is AI-estimated because receipts usually do not contain MCC directly.

## Provider selection

Set the provider and model in env, then load them through config.

Example:

```env
RECEIPTSCANNER_PROVIDER=openai
RECEIPTSCANNER_MODEL=gpt-5.4-nano
```

Azure OpenAI is supported as well. When using Azure OpenAI, configure the Azure provider settings and use the OpenAI `gpt-5.4-nano` model/deployment.

## Logging

If logging is enabled, the package may log safe diagnostics such as provider, model, mime type, input size, retry count, duration, and upstream request id/status when available.

It will not log API keys, raw receipt contents, or full base64 payloads.

## Testing

The package is designed to be tested with Orchestra Testbench and PHPUnit.

External provider calls should be mocked in tests.

## Disclaimer

ReceiptScanner uses upstream AI providers to interpret receipts. It does not guarantee accounting accuracy. Always review critical financial data before using it in production workflows.

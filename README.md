# ReceiptScanner

Extract structured receipt JSON from images or a single PDF using upstream multimodal LLM providers.

## Table of Contents

- [Why ReceiptScanner](#why-receiptscanner)
- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Quick Start](#quick-start)
- [Usage](#usage)
- [Response Schema](#response-schema)
- [Supported Inputs and Limits](#supported-inputs-and-limits)
- [Excluding Sections](#excluding-sections)
- [Provider Defaults](#provider-defaults)
- [Testing](#testing)
- [Security and Logging](#security-and-logging)
- [Extending the Package](#extending-the-package)

## Why ReceiptScanner

ReceiptScanner is a small Laravel package for one job: send receipt images or a receipt PDF to a multimodal LLM provider and get back structured PHP arrays. It is designed for applications that already have files in hand and want extraction, not a new REST service, queue worker, database layer, or OCR SaaS dependency.

The package keeps the surface area intentionally narrow. You get a Laravel facade, a service class, and provider adapters for OpenAI, Azure OpenAI, Gemini, and Anthropic. The package normalizes local paths, `SplFileInfo` instances, uploaded files, and data URI strings into provider-ready payloads, then returns decoded associative arrays that match the configured receipt schema.

It also aims to be practical in production: provider selection is config-driven, retries are limited to transient failures, invalid JSON gets one repair attempt, and logs are sanitized so secrets and file contents are not written by default.

## Features

- Laravel facade: `ReceiptScanner`
- Direct service access: `ReceiptScannerService`
- Supported entry points:
  - `ReceiptScanner::scanImages(array $images): array`
  - `ReceiptScanner::scanPdf($pdf): array`
- Provider adapters for:
  - OpenAI Responses API
  - Azure OpenAI Responses API
  - Gemini `generateContent`
  - Anthropic Messages API
- Accepts receipt inputs as:
  - local file paths
  - `SplFileInfo`
  - Laravel / Symfony uploaded files
  - data URI strings
- Sends all images for one long receipt in a single request when the provider supports multiple file parts
- Accepts exactly one PDF per `scanPdf()` call
- Returns decoded associative arrays with configurable top-level sections
- Supports section exclusion through config or environment variables
- Performs one lightweight JSON repair retry when the provider returns invalid JSON
- Retries only transient upstream failures such as HTTP 429, 5xx, connection errors, and timeouts
- Uses Laravel HTTP client only; no provider SDKs are required
- Logs sanitized operational metadata without API keys, base64 data, binary content, or full prompts

## Requirements

- PHP `^8.3`
- Laravel `11.x`, `12.x`, or `13.x`
- `illuminate/support ^11.0|^12.0|^13.0`
- `illuminate/http ^11.0|^12.0|^13.0`
- For testing:
  - `orchestra/testbench ^9.0|^10.0|^11.0`
  - `phpunit/phpunit ^11.0|^12.0`

## Installation

Install the package with Composer:

```bash
composer require jake142/receiptscanner
```

If your application does not auto-discover package config, publish the config file:

```bash
php artisan vendor:publish --tag=receiptscanner-config
```

The published config file is `config/receiptscanner.php`.

## Configuration

ReceiptScanner is configured through `config/receiptscanner.php` and the environment variables below.

### Core package settings

| Env var | Config key | Default | Purpose |
| --- | --- | --- | --- |
| `RECEIPT_SCANNER_PROVIDER` | `receiptscanner.provider` | `openai` | Selects the active provider: `openai`, `azure_openai`, `gemini`, or `anthropic` |
| `RECEIPT_SCANNER_MODEL` | `receiptscanner.model` | `null` | Optional model override; when `null`, the provider default is used |
| `RECEIPT_SCANNER_TIMEOUT` | `receiptscanner.timeout` | `90` | HTTP timeout in seconds |
| `RECEIPT_SCANNER_EXCLUDE` | `receiptscanner.exclude` | empty | Comma-separated top-level section names to omit |
| `RECEIPT_SCANNER_LOG_CHANNEL` | `receiptscanner.logging.channel` | `null` | Optional Laravel log channel |

### Retry and limits

| Config key | Default | Purpose |
| --- | --- | --- |
| `receiptscanner.retries.attempts` | `2` | Retry attempts for transient provider failures |
| `receiptscanner.retries.base_delay_ms` | `500` | Base retry delay in milliseconds |
| `receiptscanner.max_images` | `20` | Maximum number of images accepted by `scanImages()` |
| `receiptscanner.max_file_size_mb` | `20` | Maximum size per input file |

### Output fields

These booleans default to `true` and control which top-level sections are requested and returned:

- `receiptscanner.fields.merchant`
- `receiptscanner.fields.receipt`
- `receiptscanner.fields.totals`
- `receiptscanner.fields.vat_breakdown`
- `receiptscanner.fields.line_items`
- `receiptscanner.fields.mcc`
- `receiptscanner.fields.confidence`
- `receiptscanner.fields.warnings`
- `receiptscanner.fields.metadata`

### Logging

| Config key | Default | Purpose |
| --- | --- | --- |
| `receiptscanner.logging.enabled` | `true` | Enables package logging |
| `receiptscanner.logging.channel` | `null` | Optional dedicated log channel |
| `receiptscanner.logging.level` | `info` | Log level for success events |

### Provider credentials and endpoints

#### OpenAI

| Env var | Config key | Default | Purpose |
| --- | --- | --- | --- |
| `OPENAI_API_KEY` | `receiptscanner.providers.openai.api_key` | required | OpenAI API key |
| `OPENAI_BASE_URL` | `receiptscanner.providers.openai.base_url` | `https://api.openai.com/v1` | OpenAI API base URL |
| `RECEIPT_SCANNER_MODEL` | `receiptscanner.providers.openai.default_model` via shared model override | `gpt-5.4-nano` | Default model when no override is set |

#### Azure OpenAI

| Env var | Config key | Default | Purpose |
| --- | --- | --- | --- |
| `AZURE_OPENAI_API_KEY` | `receiptscanner.providers.azure_openai.api_key` | required | Azure OpenAI API key |
| `AZURE_OPENAI_ENDPOINT` | `receiptscanner.providers.azure_openai.endpoint` | required | Azure resource endpoint |
| `AZURE_OPENAI_DEPLOYMENT` | `receiptscanner.providers.azure_openai.deployment` | `gpt-5.4-nano` | Deployment name used as the model value by default |
| `AZURE_OPENAI_API_VERSION` | `receiptscanner.providers.azure_openai.api_version` | optional | Only needed if your endpoint shape requires it |

#### Gemini

| Env var | Config key | Default | Purpose |
| --- | --- | --- | --- |
| `GEMINI_API_KEY` | `receiptscanner.providers.gemini.api_key` | required | Gemini API key |
| `GEMINI_BASE_URL` | `receiptscanner.providers.gemini.base_url` | `https://generativelanguage.googleapis.com/v1beta` | Gemini API base URL |

#### Anthropic

| Env var | Config key | Default | Purpose |
| --- | --- | --- | --- |
| `ANTHROPIC_API_KEY` | `receiptscanner.providers.anthropic.api_key` | required | Anthropic API key |
| `ANTHROPIC_BASE_URL` | `receiptscanner.providers.anthropic.base_url` | `https://api.anthropic.com/v1` | Anthropic API base URL |
| `ANTHROPIC_VERSION` | `receiptscanner.providers.anthropic.version` | `2023-06-01` | Anthropic API version header |

### Example `.env`

```env
RECEIPT_SCANNER_PROVIDER=openai
RECEIPT_SCANNER_MODEL=
RECEIPT_SCANNER_TIMEOUT=90
RECEIPT_SCANNER_EXCLUDE=
RECEIPT_SCANNER_LOG_CHANNEL=

OPENAI_API_KEY=your-openai-api-key
OPENAI_BASE_URL=https://api.openai.com/v1

AZURE_OPENAI_API_KEY=your-azure-openai-api-key
AZURE_OPENAI_ENDPOINT=https://your-resource-name.openai.azure.com/openai/v1
AZURE_OPENAI_DEPLOYMENT=gpt-5.4-nano
AZURE_OPENAI_API_VERSION=

GEMINI_API_KEY=your-gemini-api-key
GEMINI_BASE_URL=https://generativelanguage.googleapis.com/v1beta

ANTHROPIC_API_KEY=your-anthropic-api-key
ANTHROPIC_BASE_URL=https://api.anthropic.com/v1
ANTHROPIC_VERSION=2023-06-01
```

## Quick Start

```php
use Jake142\ReceiptScanner\Facades\ReceiptScanner;

$result = ReceiptScanner::scanImages([
    storage_path('app/receipts/receipt-page-1.jpg'),
    storage_path('app/receipts/receipt-page-2.jpg'),
]);

// $result is an associative array with enabled receipt sections.
```

For a PDF receipt:

```php
use Jake142\ReceiptScanner\Facades\ReceiptScanner;

$result = ReceiptScanner::scanPdf(storage_path('app/receipts/receipt.pdf'));
```

## Usage

### Facade

The facade is the primary entry point:

```php
use Jake142\ReceiptScanner\Facades\ReceiptScanner;

$receipt = ReceiptScanner::scanImages([
    storage_path('app/receipts/store-front.jpg'),
    storage_path('app/receipts/store-back.jpg'),
]);
```

```php
use Jake142\ReceiptScanner\Facades\ReceiptScanner;

$receipt = ReceiptScanner::scanPdf(
    storage_path('app/receipts/receipt.pdf')
);
```

### Service class

If you prefer direct container resolution, use `ReceiptScannerService`:

```php
use Jake142\ReceiptScanner\ReceiptScannerService;

$service = app(ReceiptScannerService::class);

$receipt = $service->scanImages([
    storage_path('app/receipts/receipt.jpg'),
]);
```

### Supported input types

`scanImages(array $images)` accepts an array of:

- local file paths
- `SplFileInfo`
- Laravel uploaded files
- Symfony uploaded files
- data URI strings

`scanPdf($pdf)` accepts one of:

- a local file path
- `SplFileInfo`
- a Laravel uploaded file
- a Symfony uploaded file
- a data URI string

Notes:

- `scanImages()` requires a non-empty array.
- `scanImages()` enforces `receiptscanner.max_images`.
- `scanPdf()` accepts exactly one PDF input.
- The package validates file existence, MIME type, and per-file size before sending a request.

### Example result shape

The package returns an associative array similar to this, with disabled sections omitted:

```php
[
    'schema_version' => '1.0',
    'merchant' => [
        'name' => 'Coffee Corner',
        'address' => '123 Main St, Stockholm',
        'organization_number' => '556677-8899',
        'vat_number' => 'SE556677889901',
    ],
    'receipt' => [
        'date' => '2026-05-25',
        'time' => '14:32:10',
        'receipt_number' => 'A-10492',
        'payment_method' => 'card',
        'country' => 'SE',
        'language' => 'sv',
        'currency' => 'SEK',
    ],
    'totals' => [
        'subtotal' => 80.00,
        'discount' => null,
        'rounding' => 0.00,
        'tip' => null,
        'vat_total' => 20.00,
        'total' => 100.00,
    ],
    'vat_breakdown' => [
        [
            'rate' => 25,
            'net' => 80.00,
            'vat' => 20.00,
            'gross' => 100.00,
        ],
    ],
    'line_items' => [
        [
            'description' => 'Latte',
            'quantity' => 1,
            'unit_price' => 45.00,
            'total' => 45.00,
            'vat_rate' => 25,
            'sku' => null,
            'category' => 'beverage',
        ],
    ],
    'mcc' => [
        'code' => '5814',
        'confidence' => 0.62,
        'reason' => 'Merchant appears to be a coffee shop / beverage retailer.',
    ],
    'confidence' => [
        'overall' => 0.93,
        'date' => 0.98,
        'merchant' => 0.95,
        'total' => 0.99,
        'line_items' => 0.90,
        'vat' => 0.88,
    ],
    'warnings' => [],
    'metadata' => [
        'provider' => 'openai',
        'model' => 'gpt-5.4-nano',
        'input_type' => 'images',
        'image_count' => 2,
    ],
]
```

## Response Schema

ReceiptScanner prompts the provider to return JSON matching the enabled sections below.

```json
{
  "schema_version": "1.0",
  "merchant": {
    "name": "string|null",
    "address": "string|null",
    "organization_number": "string|null",
    "vat_number": "string|null"
  },
  "receipt": {
    "date": "YYYY-MM-DD|null",
    "time": "HH:MM:SS|null",
    "receipt_number": "string|null",
    "payment_method": "string|null",
    "country": "ISO-3166-1 alpha-2|null",
    "language": "ISO-639-1|null",
    "currency": "ISO-4217|null"
  },
  "totals": {
    "subtotal": "number|null",
    "discount": "number|null",
    "rounding": "number|null",
    "tip": "number|null",
    "vat_total": "number|null",
    "total": "number|null"
  },
  "vat_breakdown": [
    {
      "rate": "number|null",
      "net": "number|null",
      "vat": "number|null",
      "gross": "number|null"
    }
  ],
  "line_items": [
    {
      "description": "string|null",
      "quantity": "number|null",
      "unit_price": "number|null",
      "total": "number|null",
      "vat_rate": "number|null",
      "sku": "string|null",
      "category": "string|null"
    }
  ],
  "mcc": {
    "code": "string|null",
    "confidence": "number",
    "reason": "string|null"
  },
  "confidence": {
    "overall": "number",
    "date": "number|null",
    "merchant": "number|null",
    "total": "number|null",
    "line_items": "number|null",
    "vat": "number|null"
  },
  "warnings": ["string"],
  "metadata": {
    "provider": "string",
    "model": "string",
    "input_type": "images|pdf",
    "image_count": "number|null"
  }
}
```

Notes:

- Monetary values are returned as numbers, not localized currency strings.
- Unknown values should be `null`, not empty strings.
- `mcc` is a best-effort AI estimate based on merchant name or category; receipts usually do not contain MCC directly.
- If you disable a section, it is omitted from the prompt and from the expected JSON.

## Supported Inputs and Limits

- `scanImages()`
  - accepts one or more images
  - all images are treated as one long receipt
  - images are sent together in one request when the provider supports multiple file parts
  - maximum count defaults to `20`
  - maximum file size defaults to `20 MB` per file

- `scanPdf()`
  - accepts exactly one PDF
  - arrays are rejected
  - multiple PDFs are rejected
  - maximum file size defaults to `20 MB`

Supported image formats depend on the provider and model, but the package is designed around common receipt formats such as JPEG, PNG, and WebP. PDF support is handled through the provider’s document-capable API surface.

## Excluding Sections

You can reduce prompt size and response size by excluding top-level sections.

### Environment variable

```env
RECEIPT_SCANNER_EXCLUDE=vat_breakdown,line_items,mcc
```

### Config file

```php
return [
    'exclude' => ['vat_breakdown', 'line_items', 'mcc'],
];
```

Excluded sections are removed from both the prompt instructions and the expected JSON shape. This is useful when you only need merchant, receipt, totals, and confidence data.

## Provider Defaults

If `RECEIPT_SCANNER_MODEL` is not set, ReceiptScanner uses the provider default model:

- OpenAI: `gpt-5.4-nano`
- Azure OpenAI: `gpt-5.4-nano` as the default deployment/model name
- Gemini: `gemini-3.5-flash`
- Anthropic: `claude-sonnet-4-6`

Model identifiers can change over time on the provider side, so keep them configurable in your application.

## Testing

Run the test suite with PHPUnit:

```bash
vendor/bin/phpunit
```

The package tests use Orchestra Testbench and Laravel HTTP fakes. External provider calls are mocked, so the suite does not hit OpenAI, Azure OpenAI, Gemini, or Anthropic during testing.

## Security and Logging

ReceiptScanner is designed to avoid leaking sensitive data in logs.

By default, the package logs only sanitized operational metadata such as:

- provider
- model
- input type
- image count
- MIME types
- approximate file sizes
- enabled and excluded sections
- duration
- request ID when available

It does not log:

- API keys
- base64 file data
- binary content
- extracted receipt text
- full prompts
- full provider responses

## Extending the Package

ReceiptScanner v1 includes four adapters only: OpenAI, Azure OpenAI, Gemini, and Anthropic. The package is intentionally small, but the provider selection flow is simple enough to add another adapter later if you need one.

If you extend it, keep the same pattern:

- normalize inputs once
- build the prompt from enabled sections
- call the provider adapter through `extract(array $context): array`
- decode JSON
- retry transient failures only
- keep logs sanitized

For most applications, the public API is just the facade:

```php
use Jake142\ReceiptScanner\Facades\ReceiptScanner;

$receipt = ReceiptScanner::scanPdf(storage_path('app/receipts/receipt.pdf'));
```

# ReceiptScanner

Scan receipts from images and PDFs with Laravel AI provider wrappers.

## Table of Contents

- [Why ReceiptScanner](#why-receiptscanner)
- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Quick Start](#quick-start)
- [Usage](#usage)
- [Testing](#testing)

## Why ReceiptScanner

ReceiptScanner is a small Laravel package for turning local receipt files into structured receipt JSON using upstream AI provider APIs. It is designed for applications that already have receipt images or PDFs on disk and want a simple facade-based entry point instead of wiring each provider manually.

This package wraps provider APIs directly; it is not a hosted ReceiptScanner service and it does not add routes, controllers, billing, queues, or persistence. The focus is on practical receipt extraction with a minimal Laravel integration surface: a facade, a manager, provider adapters, and published configuration.

The package supports OpenAI, Azure OpenAI, Gemini, and Anthropic provider adapters. The v0.1.2-style bugfix release also tightens Azure OpenAI URL handling, completes provider configuration, and keeps the public `scan()`, `scanImages()`, and `scanPdf()` API consistent with the README.

## Features

- Laravel facade entry point: `Jake142\ReceiptScanner\Facades\ReceiptScanner`
- Primary scan method: `ReceiptScanner::scan($path, array $options = [])`
- Explicit image and PDF methods: `scanImages()` and `scanPdf()`
- Automatic dispatch from `scan()` based on file path extension or MIME type
- Provider adapters for:
  - OpenAI
  - Azure OpenAI
  - Gemini
  - Anthropic
- Structured receipt output intended to preserve fields such as:
  - merchant
  - date
  - total / amount
  - tax / VAT
  - currency
  - line items
  - MCC, confidence, and metadata when supported
- Published config at `config/receiptscanner.php`
- Configurable default provider and provider-specific models / credentials
- Azure OpenAI endpoint mode support for `v1` and `legacy`
- Safe error handling and logging expectations for upstream failures

## Requirements

- PHP `^8.3`
- Laravel `illuminate/support` `^11.0|^12.0|^13.0`
- Laravel `illuminate/http` `^11.0|^12.0|^13.0`
- For package development and testing:
  - `orchestra/testbench` `^9.0|^10.0|^11.0`
  - `phpunit/phpunit` `^11.0|^12.0`

## Installation

Install the package with Composer:

```bash
composer require jake142/receiptscanner
```

If you want to publish the package configuration into your Laravel app:

```bash
php artisan vendor:publish --tag=receiptscanner-config
```

## Configuration

ReceiptScanner reads provider credentials and runtime options from `config/receiptscanner.php` and environment variables.

### Published config keys

The package config is expected to include these keys:

- `receiptscanner.default_provider`
- `receiptscanner.providers.openai.api_key`
- `receiptscanner.providers.openai.model`
- `receiptscanner.providers.azure_openai.endpoint`
- `receiptscanner.providers.azure_openai.api_key`
- `receiptscanner.providers.azure_openai.deployment`
- `receiptscanner.providers.azure_openai.api_version`
- `receiptscanner.providers.azure_openai.endpoint_mode`
- `receiptscanner.providers.gemini.api_key`
- `receiptscanner.providers.gemini.model`
- `receiptscanner.providers.anthropic.api_key`
- `receiptscanner.providers.anthropic.model`
- `receiptscanner.timeout`
- `receiptscanner.retries`
- `receiptscanner.fields`
- `receiptscanner.logging`

### Environment variables

Documented environment keys used by the package:

```env
RECEIPTSCANNER_PROVIDER=openai
RECEIPTSCANNER_TIMEOUT=30
RECEIPTSCANNER_RETRIES=2
RECEIPTSCANNER_LOGGING=false

OPENAI_API_KEY=
OPENAI_MODEL=gpt-4.1-mini
OPENAI_BASE_URL=

AZURE_OPENAI_ENDPOINT=https://opencard.openai.azure.com
AZURE_OPENAI_API_KEY=
AZURE_OPENAI_DEPLOYMENT=gpt-5.4-nano
AZURE_OPENAI_API_VERSION=2024-10-21
AZURE_OPENAI_ENDPOINT_MODE=v1

GEMINI_API_KEY=
GEMINI_MODEL=gemini-2.5-flash
GEMINI_BASE_URL=

ANTHROPIC_API_KEY=
ANTHROPIC_MODEL=claude-sonnet-4-20250514
ANTHROPIC_BASE_URL=
```

### Provider notes

- **OpenAI**: uses `OPENAI_API_KEY` and `OPENAI_MODEL`.
- **Azure OpenAI**: uses `AZURE_OPENAI_ENDPOINT`, `AZURE_OPENAI_API_KEY`, `AZURE_OPENAI_DEPLOYMENT`, `AZURE_OPENAI_API_VERSION`, and `AZURE_OPENAI_ENDPOINT_MODE`.
  - `endpoint_mode=v1` is the recommended default.
  - In `v1` mode, the provider should build `{endpoint}/openai/v1/responses` and ignore `api-version` in the URL.
  - In `legacy` mode, the provider should build `{endpoint}/openai/responses?api-version=...`.
- **Gemini**: uses `GEMINI_API_KEY` and `GEMINI_MODEL`, with `gemini-2.5-flash` as the default model.
- **Anthropic**: uses `ANTHROPIC_API_KEY` and `ANTHROPIC_MODEL`, with `claude-sonnet-4-20250514` as the default model.

### Example config shape

```php
<?php

declare(strict_types=1);

return [
    'default_provider' => env('RECEIPTSCANNER_PROVIDER', 'openai'),

    'providers' => [
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_MODEL', 'gpt-4.1-mini'),
            'base_url' => env('OPENAI_BASE_URL'),
        ],

        'azure_openai' => [
            'endpoint' => env('AZURE_OPENAI_ENDPOINT'),
            'api_key' => env('AZURE_OPENAI_API_KEY'),
            'deployment' => env('AZURE_OPENAI_DEPLOYMENT'),
            'api_version' => env('AZURE_OPENAI_API_VERSION'),
            'endpoint_mode' => env('AZURE_OPENAI_ENDPOINT_MODE', 'v1'),
        ],

        'gemini' => [
            'api_key' => env('GEMINI_API_KEY'),
            'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
            'base_url' => env('GEMINI_BASE_URL'),
        ],

        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-20250514'),
            'base_url' => env('ANTHROPIC_BASE_URL'),
        ],
    ],

    'timeout' => env('RECEIPTSCANNER_TIMEOUT', 30),
    'retries' => env('RECEIPTSCANNER_RETRIES', 2),
    'logging' => env('RECEIPTSCANNER_LOGGING', false),

    'fields' => [
        'merchant',
        'date',
        'total',
        'amount',
        'tax',
        'vat',
        'currency',
        'line_items',
        'mcc',
        'confidence',
        'metadata',
    ],
];
```

## Quick Start

After installing and configuring your provider credentials, scan a local receipt file with the facade:

```php
use Jake142\ReceiptScanner\Facades\ReceiptScanner;

$result = ReceiptScanner::scan(storage_path('app/receipts/dinner-receipt.jpg'));

print_r($result);
```

A typical structured result is an associative array with receipt fields such as:

```php
[
    'merchant' => 'Coffee Shop',
    'date' => '2025-05-27',
    'total' => 18.40,
    'amount' => 18.40,
    'tax' => 1.40,
    'currency' => 'USD',
    'line_items' => [
        ['name' => 'Latte', 'quantity' => 1, 'price' => 6.20],
        ['name' => 'Sandwich', 'quantity' => 1, 'price' => 12.20],
    ],
]
```

## Usage

### Scan a single receipt file

`scan()` is the primary entry point. It should detect whether the file is an image or a PDF and dispatch accordingly.

```php
use Jake142\ReceiptScanner\Facades\ReceiptScanner;

$result = ReceiptScanner::scan(storage_path('app/receipts/receipt.pdf'));
```

### Scan one or more images

Use `scanImages()` when you already know the input is image-based.

```php
use Jake142\ReceiptScanner\Facades\ReceiptScanner;

$result = ReceiptScanner::scanImages([
    storage_path('app/receipts/receipt-front.jpg'),
    storage_path('app/receipts/receipt-back.png'),
]);
```

You can also pass a single image path:

```php
use Jake142\ReceiptScanner\Facades\ReceiptScanner;

$result = ReceiptScanner::scanImages(storage_path('app/receipts/receipt.jpg'));
```

### Scan a PDF receipt

Use `scanPdf()` for a single PDF file.

```php
use Jake142\ReceiptScanner\Facades\ReceiptScanner;

$result = ReceiptScanner::scanPdf(storage_path('app/receipts/receipt.pdf'));
```

### Select a provider per call

The default provider comes from `receiptscanner.default_provider`, but you can override it with options when supported by the manager.

```php
use Jake142\ReceiptScanner\Facades\ReceiptScanner;

$result = ReceiptScanner::scan(
    storage_path('app/receipts/receipt.jpg'),
    [
        'provider' => 'azure_openai',
    ]
);
```

### Configure Azure OpenAI endpoint mode

For Azure OpenAI, the recommended mode is `v1`:

```env
AZURE_OPENAI_ENDPOINT_MODE=v1
```

Use `legacy` only if you need the older `/openai/responses?api-version=...` behavior:

```env
AZURE_OPENAI_ENDPOINT_MODE=legacy
AZURE_OPENAI_API_VERSION=2024-10-21
```

### Supported inputs

ReceiptScanner is intended for local receipt files in formats supported by the configured provider:

- JPEG
- PNG
- PDF

## Testing

The package test suite uses PHPUnit with Orchestra Testbench.

Run the tests with:

```bash
vendor/bin/phpunit
```

External provider calls are mocked in tests, so the suite should not contact OpenAI, Azure OpenAI, Gemini, or Anthropic.

If you are extending the package, keep tests focused on:

- Azure OpenAI URL construction for `v1` and `legacy` modes
- trailing slash normalization for Azure endpoints
- published config keys for Gemini and Anthropic
- facade / manager method reachability for `scan()`, `scanImages()`, and `scanPdf()`

## Notes

- This package is a Laravel AI provider wrapper, not a hosted API.
- Do not log API keys, authorization headers, or base64 receipt content.
- Keep receipt output backward compatible when adding provider improvements.

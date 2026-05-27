# ReceiptScanner

Parse receipts from images and PDFs with Laravel-powered OpenAI and Azure OpenAI providers.

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

ReceiptScanner is a focused Laravel package for turning receipt images and PDFs into structured receipt data by calling upstream multimodal LLM providers. It is designed for applications that already have a receipt-scanning workflow and want a small, practical integration layer rather than a new service, queue, or database-backed pipeline.

The package currently wraps the OpenAI Responses API and the Azure OpenAI Responses API. That means you keep your existing Laravel app structure, while ReceiptScanner handles provider selection, request construction, and response parsing. The package is intentionally narrow in scope: it is an AI provider wrapper for receipt parsing, not a standalone OCR platform and not a fictional REST API of its own.

A recent Azure OpenAI parsing fix also makes the package more resilient to real Responses API payloads. Successful responses no longer need to expose a top-level `output_text` field; ReceiptScanner can extract generated text from nested `output[].content[]` blocks as documented by the upstream APIs.

## Features

- Laravel facade and bound service entrypoint for receipt scanning.
- OpenAI Responses API support.
- Azure OpenAI Responses API support.
- Provider selection via config or per-call options.
- Structured receipt JSON output using the package’s existing schema.
- Multimodal receipt inputs, including local files and supported receipt image/PDF content.
- Shared Responses API text extraction logic that tolerates both top-level `output_text` and nested `output` content blocks.
- PHPUnit test coverage with mocked HTTP calls; no live provider calls in tests.

## Requirements

- PHP `^8.3`
- Laravel / Illuminate Support `^11.0|^12.0|^13.0`
- Laravel / Illuminate HTTP `^11.0|^12.0|^13.0`
- PHPUnit `^11.0|^12.0` for development
- Orchestra Testbench `^9.0|^10.0|^11.0` for development

## Installation

Install the package with Composer:

```bash
composer require jake142/receiptscanner
```

If you are using Laravel package discovery, the service provider and facade alias should be available automatically. If your application disables discovery, register the package service provider manually in the usual Laravel way.

## Configuration

ReceiptScanner reads its settings from `config/receiptscanner.php` and the environment variables listed below.

### Provider selection

Choose the active provider with either:

- `receiptscanner.provider`
- `receiptscanner.default`
- `RECEIPTSCANNER_PROVIDER`
- `RECEIPTSCANNER_DEFAULT`

Supported provider values are the package’s existing OpenAI and Azure OpenAI slugs.

### Environment variables

Documented environment keys:

- `RECEIPTSCANNER_PROVIDER`
- `RECEIPTSCANNER_DEFAULT`
- `OPENAI_API_KEY`
- `OPENAI_MODEL`
- `AZURE_OPENAI_API_KEY`
- `AZURE_OPENAI_AUTH_TOKEN`
- `AZURE_OPENAI_ENDPOINT`
- `AZURE_OPENAI_DEPLOYMENT`
- `AZURE_OPENAI_MODEL`
- `AZURE_OPENAI_API_VERSION`

### Config keys

Documented config keys:

- `receiptscanner.default`
- `receiptscanner.provider`
- `receiptscanner.providers.openai.api_key`
- `receiptscanner.providers.openai.model`
- `receiptscanner.providers.azure_openai.api_key`
- `receiptscanner.providers.azure_openai.auth_token`
- `receiptscanner.providers.azure_openai.endpoint`
- `receiptscanner.providers.azure_openai.deployment`
- `receiptscanner.providers.azure_openai.model`
- `receiptscanner.providers.azure_openai.api_version`

### Example configuration

```php
<?php

return [
    'default' => env('RECEIPTSCANNER_DEFAULT', env('RECEIPTSCANNER_PROVIDER', 'openai')),
    'provider' => env('RECEIPTSCANNER_PROVIDER', env('RECEIPTSCANNER_DEFAULT', 'openai')),

    'providers' => [
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_MODEL'),
        ],

        'azure_openai' => [
            'api_key' => env('AZURE_OPENAI_API_KEY'),
            'auth_token' => env('AZURE_OPENAI_AUTH_TOKEN'),
            'endpoint' => env('AZURE_OPENAI_ENDPOINT'),
            'deployment' => env('AZURE_OPENAI_DEPLOYMENT'),
            'model' => env('AZURE_OPENAI_MODEL'),
            'api_version' => env('AZURE_OPENAI_API_VERSION', '2024-10-21'),
        ],
    ],
];
```

## Quick Start

1. Install the package.
2. Set the provider credentials in your `.env` file.
3. Call the facade with a local receipt file path.

```php
use Jake142\ReceiptScanner\Facades\ReceiptScanner;

$result = ReceiptScanner::scan(storage_path('app/receipts/sample-input.jpg'));

print_r($result);
```

The returned value is an associative array using the package’s existing receipt JSON schema. A typical result includes fields such as merchant, date, totals, currency, items, and raw receipt data.

## Usage

### Scan a local receipt file with the facade

```php
use Jake142\ReceiptScanner\Facades\ReceiptScanner;

$receipt = ReceiptScanner::scan(storage_path('app/receipts/dinner-receipt.pdf'));

// Example shape depends on the upstream model output and your existing schema.
// The package returns structured receipt data as an array.
var_dump($receipt);
```

### Choose a provider per call

```php
use Jake142\ReceiptScanner\Facades\ReceiptScanner;

$receipt = ReceiptScanner::scan(
    storage_path('app/receipts/grocery.png'),
    [
        'provider' => 'azure_openai',
    ]
);
```

### Resolve the manager from the container

```php
use Jake142\ReceiptScanner\ReceiptScannerManager;

$scanner = app(ReceiptScannerManager::class);

$receipt = $scanner->scan(storage_path('app/receipts/office-supplies.jpg'));
```

### Example receipt output

```php
[
    'merchant' => 'Corner Market',
    'date' => '2026-05-26',
    'total' => 18.42,
    'subtotal' => 17.10,
    'tax' => 1.32,
    'currency' => 'USD',
    'items' => [
        ['name' => 'Coffee', 'quantity' => 1, 'price' => 4.50],
        ['name' => 'Sandwich', 'quantity' => 1, 'price' => 8.60],
    ],
    'raw' => [/* provider-specific parsed receipt payload */],
]
```

### Supported input formats

ReceiptScanner is intended for receipt images and PDFs. The package summaries and provider guidance reference common receipt inputs such as JPEG, PNG, and PDF files.

## Testing

Run the test suite with PHPUnit:

```bash
vendor/bin/phpunit
```

The package’s tests use mocked HTTP responses, so they do not call live OpenAI or Azure OpenAI endpoints. This keeps the suite fast, repeatable, and safe to run in CI.

If you are extending the package, prefer adding regression tests around the provider adapters and their response parsing. In particular, Azure OpenAI responses should be covered for both top-level `output_text` and nested `output[].content[].text` shapes.

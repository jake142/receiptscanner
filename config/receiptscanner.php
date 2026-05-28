<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | ReceiptScanner Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the default AI provider and model used by the facade/service.
    | Supported providers:
    | - openai
    | - azure_openai
    | - gemini
    | - anthropic
    |
    | The package reads provider/model values from env via this config file.
    | Default models are modern multimodal models, with OpenAI/Azure OpenAI
    | using gpt-5.4-nano by default.
    |
    */

    'default_provider' => env('RECEIPTSCANNER_PROVIDER', 'openai'),

    /*
    |--------------------------------------------------------------------------
    | Provider Configuration
    |--------------------------------------------------------------------------
    */

    'providers' => [
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'model' => env('OPENAI_MODEL', 'gpt-5.4-nano'),
        ],

        'azure_openai' => [
            'endpoint' => env('AZURE_OPENAI_ENDPOINT'),
            'api_key' => env('AZURE_OPENAI_API_KEY'),
            'deployment' => env('AZURE_OPENAI_DEPLOYMENT', 'gpt-5.4-nano'),
            'api_version' => env('AZURE_OPENAI_API_VERSION', null),
            'endpoint_mode' => env('AZURE_OPENAI_ENDPOINT_MODE', 'v1'),
        ],

        'gemini' => [
            'api_key' => env('GEMINI_API_KEY', env('GOOGLE_API_KEY')),
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
            'model' => env('GEMINI_MODEL', 'gemini-2.5-pro'),
        ],

        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1'),
            'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-20250514'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Request / Retry / Logging
    |--------------------------------------------------------------------------
    */

    'timeout' => (int) env('RECEIPTSCANNER_TIMEOUT', 60),

    'retries' => (int) env('RECEIPTSCANNER_RETRIES', 2),

    'max_file_size_mb' => (int) env('RECEIPTSCANNER_MAX_FILE_SIZE_MB', 32),

    'logging' => [
        'enabled' => (bool) env('RECEIPTSCANNER_LOGGING', false),
        'log_receipt_content' => (bool) env('RECEIPTSCANNER_LOG_RECEIPT_CONTENT', false),
        'channel' => env('RECEIPTSCANNER_LOG_CHANNEL', null),
        'level' => env('RECEIPTSCANNER_LOG_LEVEL', 'info'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Prompt / Field Exclusions
    |--------------------------------------------------------------------------
    |
    | All fields are enabled by default. You may exclude parts to reduce the
    | prompt size and the returned JSON payload.
    |
    */

    'enabled_fields' => [
        'merchant' => true,
        'total_amount' => true,
        'currency' => true,
        'date' => true,
        'vat_amount' => true,
        'mcc' => true,
        'vats' => true,
        'line_items' => true,
        'confidence' => true,
        'tip' => true,
        'purchase_country' => true,
        'purchase_city' => true,
    ],

    'exclude' => array_values(array_filter(array_map(
        static fn ($value): string => is_string($value) ? trim($value) : '',
        explode(',', (string) env('RECEIPTSCANNER_EXCLUDE', ''))
    ))),

    'prompt' => [
        'extraction' => <<<PROMPT
You are a receipt extraction engine.
Analyze all provided images together as one receipt. If multiple images are provided, merge them in the correct order.
If a PDF is provided, analyze the full PDF as one receipt.
Return JSON only. No markdown, no code fences, no commentary.
Use null for unknown scalar values and [] for unknown arrays.
Use numeric values without currency symbols. Normalize decimal separators to dot.
DATE rules:
- The date field is the purchase/receipt date printed on the receipt (not card terminal time, authorization time, or footer marketing text).
- Return date as YYYY-MM-DD.
- Parse Swedish formats such as YY-MM-DD, DD.MM.YYYY, and DD/MM/YYYY from the receipt body or header.
- If multiple dates appear, prefer the transaction/receipt date near totals or "KÖP"/"DATUM", not "Kontaktlöst chip"/terminal metadata.
- Do not guess a date that is not supported by visible receipt text.
MCC rules:
- mcc must be a best-effort 4-digit ISO 18245 merchant category code inferred from merchant name, store type, and line items.
- Receipts rarely print MCC; estimate when the merchant is identifiable.
- Return null for mcc only if the merchant cannot be identified at all.
VAT rules:
- VAT must always be returned as vats: array<object> and never as a string.
- Each VAT object must contain rate, amount, amount_inc_vat, amount_ex_vat.
- Each line item object must contain description, quantity, unit_price, amount.
PROMPT,
    ],
];

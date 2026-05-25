<?php

declare(strict_types=1);

return [
    'provider' => env('RECEIPT_SCANNER_PROVIDER', 'openai'),

    'model' => env('RECEIPT_SCANNER_MODEL'),

    'timeout' => (int) env('RECEIPT_SCANNER_TIMEOUT', 90),

    'retries' => [
        'attempts' => 2,
        'base_delay_ms' => 500,
    ],

    'max_images' => 20,

    'max_file_size_mb' => 20,

    'exclude' => array_values(array_filter(array_map(
        static fn (string $section): string => trim($section),
        explode(',', (string) env('RECEIPT_SCANNER_EXCLUDE', ''))
    ), static fn (string $section): bool => $section !== '')),

    'fields' => [
        'merchant' => true,
        'receipt' => true,
        'totals' => true,
        'vat_breakdown' => true,
        'line_items' => true,
        'mcc' => true,
        'confidence' => true,
        'warnings' => true,
        'metadata' => true,
    ],

    'logging' => [
        'enabled' => true,
        'channel' => env('RECEIPT_SCANNER_LOG_CHANNEL'),
        'level' => 'info',
    ],

    'providers' => [
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'default_model' => 'gpt-5.4-nano',
        ],

        'azure_openai' => [
            'api_key' => env('AZURE_OPENAI_API_KEY'),
            'endpoint' => env('AZURE_OPENAI_ENDPOINT'),
            'deployment' => env('AZURE_OPENAI_DEPLOYMENT', 'gpt-5.4-nano'),
            'api_version' => env('AZURE_OPENAI_API_VERSION'),
            'default_model' => 'gpt-5.4-nano',
        ],

        'gemini' => [
            'api_key' => env('GEMINI_API_KEY'),
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
            'default_model' => 'gemini-3.5-flash',
        ],

        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1'),
            'version' => env('ANTHROPIC_VERSION', '2023-06-01'),
            'default_model' => 'claude-sonnet-4-6',
        ],
    ],
];

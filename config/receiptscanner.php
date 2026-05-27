<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Receipt Scanner Provider
    |--------------------------------------------------------------------------
    |
    | Supported providers: "openai", "azure_openai", "gemini", "anthropic".
    |
    */

    'default_provider' => env('RECEIPTSCANNER_PROVIDER', 'openai'),

    /*
    |--------------------------------------------------------------------------
    | Provider Configuration
    |--------------------------------------------------------------------------
    |
    | OpenAI:
    |   OPENAI_API_KEY
    |   OPENAI_MODEL
    |
    | Azure OpenAI:
    |   AZURE_OPENAI_ENDPOINT
    |   AZURE_OPENAI_API_KEY
    |   AZURE_OPENAI_DEPLOYMENT
    |   AZURE_OPENAI_API_VERSION
    |   AZURE_OPENAI_ENDPOINT_MODE
    |
    | Azure endpoint modes:
    |   v1     => {endpoint}/openai/v1/responses, without api-version query
    |   legacy => {endpoint}/openai/responses?api-version={api_version}
    |
    | Gemini:
    |   GEMINI_API_KEY
    |   GEMINI_MODEL
    |
    | Anthropic:
    |   ANTHROPIC_API_KEY
    |   ANTHROPIC_MODEL
    |
    */

    'providers' => [
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_MODEL', 'gpt-4.1-mini'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
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
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        ],

        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-20250514'),
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Request Options
    |--------------------------------------------------------------------------
    |
    | Timeout is expressed in seconds. Retries should be used only by provider
    | adapters for transient upstream failures such as rate limits and 5xx
    | responses.
    |
    */

    'timeout' => (int) env('RECEIPTSCANNER_TIMEOUT', 60),

    'retries' => (int) env('RECEIPTSCANNER_RETRIES', 2),

    /*
    |--------------------------------------------------------------------------
    | Receipt Fields
    |--------------------------------------------------------------------------
    |
    | These fields describe the structured receipt JSON this package asks
    | providers to return. Keep these stable to preserve backward compatibility.
    |
    */

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

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | When enabled, providers may log safe diagnostics only. API keys,
    | Authorization headers, api-key headers, and base64 receipt/PDF/image
    | content must never be logged.
    |
    */

    'logging' => env('RECEIPTSCANNER_LOGGING', false),
];

<?php

declare(strict_types=1);

return [
    'default' => env('RECEIPTSCANNER_PROVIDER', env('RECEIPTSCANNER_DEFAULT', 'openai')),

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

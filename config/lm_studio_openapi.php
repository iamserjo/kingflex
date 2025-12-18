<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | LM Studio (OpenAI-compatible) API
    |--------------------------------------------------------------------------
    |
    | This project can talk to an OpenAI-compatible /chat/completions endpoint
    | (e.g. LM Studio server). This is used for vision requests with screenshots.
    |
    */

    'base_url' => env('LM_STUDIO_OPENAPI_BASE_URL'),

    'model' => env('LM_STUDIO_OPENAPI_MODEL', 'ministralai/ministral-3-3b'),

    'timeout' => (int) env('LM_STUDIO_OPENAPI_TIMEOUT', 120),

    'max_tokens' => (int) env('LM_STUDIO_OPENAPI_MAX_TOKENS', 2048),

    'temperature' => (float) env('LM_STUDIO_OPENAPI_TEMPERATURE', 0.2),
];



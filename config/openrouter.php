<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OpenRouter API Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for OpenRouter API used for AI analysis and embeddings
    | https://openrouter.ai/docs/api/reference
    |
    */

    // OpenRouter API key
    'api_key' => env('OPENROUTER_API_KEY'),

    // Base URL for OpenRouter API
    'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),

    // Request timeout in seconds
    'timeout' => env('OPENROUTER_TIMEOUT', 120),

    /*
    |--------------------------------------------------------------------------
    | Chat Completion Model
    |--------------------------------------------------------------------------
    |
    | Model used for page analysis (type detection, summary, keywords, etc.)
    |
    */

    'chat_model' => env('OPENROUTER_CHAT_MODEL', 'google/gemini-2.5-flash-preview-05-20'),

    // Maximum tokens for chat completion
    'chat_max_tokens' => env('OPENROUTER_CHAT_MAX_TOKENS', 4096),

    // Temperature for chat completion (0-2, lower = more deterministic)
    'chat_temperature' => env('OPENROUTER_CHAT_TEMPERATURE', 0.3),

    /*
    |--------------------------------------------------------------------------
    | Embedding Model
    |--------------------------------------------------------------------------
    |
    | Model used for generating vector embeddings
    | https://openrouter.ai/docs/api/reference/embeddings
    |
    */

    'embedding_model' => env('OPENROUTER_EMBEDDING_MODEL', 'openai/text-embedding-3-small'),

    // Embedding dimensions (depends on model)
    'embedding_dimensions' => env('OPENROUTER_EMBEDDING_DIMENSIONS', 1536),

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    */

    // Maximum requests per minute
    'rate_limit_per_minute' => env('OPENROUTER_RATE_LIMIT', 60),

    // Retry attempts on failure
    'retry_attempts' => env('OPENROUTER_RETRY_ATTEMPTS', 3),

    // Retry delay in milliseconds
    'retry_delay' => env('OPENROUTER_RETRY_DELAY', 1000),

    /*
    |--------------------------------------------------------------------------
    | Site Information (for OpenRouter headers)
    |--------------------------------------------------------------------------
    */

    'site_url' => env('APP_URL', 'https://marketking.local'),

    'site_name' => env('APP_NAME', 'MarketKing'),

];


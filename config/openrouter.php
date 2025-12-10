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

    'chat_model' => env('OPENROUTER_CHAT_MODEL', 'google/gemini-2.5-flash-lite'),

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

    // Available models: https://openrouter.ai/models?output_modalities=embeddings
    // Free: thenlper/gte-large (1024 dim), sentence-transformers/all-minilm-l6-v2 (384 dim)
    // Paid: openai/text-embedding-ada-002 (1536 dim), openai/text-embedding-3-small (1536 dim)
    // Default to a high-dimension model; override via env as needed
    'embedding_model' => env('OPENROUTER_EMBEDDING_MODEL', 'text-embedding-3-large'),

    // Embedding dimensions (depends on model)
    // thenlper/gte-large: 1024
    // openai/text-embedding-ada-002, text-embedding-3-small: 1536
    // openai/text-embedding-3-large: 3072
    // text-embedding-3-large outputs 3072 dimensions, but pgvector ivfflat index
    // limits to 2000 dims. We store first 2000 dims by default.
    // adjust if you change model (e.g., gemini-embedding-001 is 768)
    'embedding_dimensions' => env('OPENROUTER_EMBEDDING_DIMENSIONS', 2000),

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


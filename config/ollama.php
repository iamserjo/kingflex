<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ollama API Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Ollama API used for local AI model inference.
    | Ollama provides a simple API for running LLMs locally.
    |
    */

    // Base URL for Ollama API
    'base_url' => env('OLLAMA_BASE_URL'),

    // Default model for chat completions
    // Use `ollama list` to see available models
    'model' => env('OLLAMA_MODEL', 'ministral-3:3b'),

    // Request timeout in seconds
    'timeout' => env('OLLAMA_TIMEOUT', 120),

    // Maximum tokens for chat completion (num_predict in Ollama)
    // Increased to 32768 to avoid truncation of long content
    'max_tokens' => env('OLLAMA_MAX_TOKENS', 32768),

    // Temperature for chat completion (0-2, lower = more deterministic)
    'temperature' => env('OLLAMA_TEMPERATURE', 0.3),

];


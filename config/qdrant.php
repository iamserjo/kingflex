<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Qdrant Configuration
    |--------------------------------------------------------------------------
    |
    | Qdrant is used as an external vector database for storing vectors + payload.
    | In Docker Compose we run it as the `qdrant` service, so inside the
    | Laravel container the default host is `http://qdrant:6333`.
    |
    */

    'host' => env('QDRANT_HOST', 'http://qdrant:6333'),

    // Optional API key (if your Qdrant instance is protected)
    'api_key' => env('QDRANT_API_KEY'),

    'timeout' => (int) env('QDRANT_TIMEOUT', 30),

    // Default collection for pages
    'collection' => env('QDRANT_COLLECTION', 'pages'),

    // Vector size must match the embedding dimensionality we send to Qdrant
    'vector_size' => (int) env('QDRANT_VECTOR_SIZE', (int) config('openrouter.embedding_dimensions', 2000)),

    // Supported: Cosine, Dot, Euclid, Manhattan
    'distance' => env('QDRANT_DISTANCE', 'Cosine'),

    /*
    |--------------------------------------------------------------------------
    | Qdrant Query Generator Model (OpenRouter)
    |--------------------------------------------------------------------------
    |
    | Model used to generate the Qdrant query plan (query_text + filters).
    | Example: openai/gpt-5.1-codex-max
    |
    */
    'query_generator_model' => env('QDRANT_QUERY_GENERATOR_MODEL'),
];




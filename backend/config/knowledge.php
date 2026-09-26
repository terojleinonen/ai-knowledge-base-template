<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI Providers & Models
    |--------------------------------------------------------------------------
    |
    | Provider names refer to entries in config/ai.php. A null model falls back
    | to the provider's default. Use "ollama" for fully local, free inference.
    |
    */

    'chat' => [
        'provider' => env('KB_CHAT_PROVIDER', 'openai'),
        'model' => env('KB_CHAT_MODEL', 'gpt-4o-mini'),
        'timeout' => (int) env('KB_CHAT_TIMEOUT', 120),
    ],

    'embeddings' => [
        'provider' => env('KB_EMBEDDINGS_PROVIDER', 'openai'),
        'model' => env('KB_EMBEDDINGS_MODEL', 'text-embedding-3-small'),
        'dimensions' => filled(env('KB_EMBEDDINGS_DIMENSIONS')) ? (int) env('KB_EMBEDDINGS_DIMENSIONS') : null,
        'batch_size' => (int) env('KB_EMBEDDINGS_BATCH_SIZE', 64),
        'timeout' => (int) env('KB_EMBEDDINGS_TIMEOUT', 60),
        // Some models need task prefixes, e.g. nomic-embed-text: "search_query: " / "search_document: ".
        'query_prefix' => env('KB_EMBEDDINGS_QUERY_PREFIX', ''),
        'document_prefix' => env('KB_EMBEDDINGS_DOCUMENT_PREFIX', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ingestion
    |--------------------------------------------------------------------------
    */

    'uploads' => [
        'disk' => env('KB_UPLOAD_DISK', 'local'),
        'max_kilobytes' => (int) env('KB_UPLOAD_MAX_KB', 20480),
        'mimes' => ['pdf', 'txt', 'md', 'docx'],
    ],

    'chunking' => [
        'size' => (int) env('KB_CHUNK_SIZE', 1200),
        'overlap' => (int) env('KB_CHUNK_OVERLAP', 200),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retrieval
    |--------------------------------------------------------------------------
    */

    'retrieval' => [
        'top_k' => (int) env('KB_RETRIEVAL_TOP_K', 6),
        'min_score' => (float) env('KB_RETRIEVAL_MIN_SCORE', 0.2),
    ],

    'citations' => [
        // Verify and fix [n] citations against the sources after generation (see CitationRepairer).
        'repair' => (bool) env('KB_CITATION_REPAIR', true),
    ],

    'history' => [
        // Number of previous messages sent to the model as conversation context.
        'messages' => (int) env('KB_HISTORY_MESSAGES', 6),
    ],

];

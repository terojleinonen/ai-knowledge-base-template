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
        'model' => env('KB_CHAT_MODEL', 'gpt-6-luna'),
        'timeout' => (int) env('KB_CHAT_TIMEOUT', 120),
        // Anthropic only. Effort: low|medium|high|xhigh|max (Claude Opus 5.5 defaults to medium).
        'effort' => env('KB_CHAT_EFFORT') ?: null,
        // Anthropic only. "default" retries a declined request on Anthropic's recommended
        // fallback model, server-side (Claude Opus 5.5 / Opus 5 / Fable 5.1 / Sonnet 5.5).
        'fallbacks' => env('KB_CHAT_FALLBACKS') ?: null,
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
        // Uploads go to R2 once its bucket is configured; local disk otherwise (lost on restart on free hosting).
        'disk' => env('KB_UPLOAD_DISK') ?: (env('R2_BUCKET') ? 'r2' : 'local'),
        'max_kilobytes' => (int) env('KB_UPLOAD_MAX_KB', 20480),
        'mimes' => ['pdf', 'txt', 'md', 'docx'],
        // Uncompressed size limit for a DOCX body: a small .docx can be a zip bomb.
        'max_docx_xml_bytes' => 50 * 1024 * 1024,
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

    /*
    |--------------------------------------------------------------------------
    | Public demo & cost controls
    |--------------------------------------------------------------------------
    |
    | For a public deployment where every question costs API credit. Null
    | limits mean unlimited. Guest accounts get a private copy of the demo
    | documents and are deleted after `guest_ttl_hours`.
    |
    */

    'demo' => [
        'enabled' => (bool) env('KB_DEMO_ENABLED', false),
        'dataset' => env('KB_DEMO_DATASET', 'evals/northwind'),
        'guest_ttl_hours' => (int) env('KB_DEMO_GUEST_TTL_HOURS', 24),
    ],

    'registration' => [
        'enabled' => (bool) env('KB_REGISTRATION_ENABLED', true),
    ],

    // Header carrying the visitor's IP when the edge sets it (e.g. CF-Connecting-IP); see App\Support\ClientIp.
    'client_ip_header' => env('KB_CLIENT_IP_HEADER') ?: null,

    'limits' => [
        'questions_per_user_per_day' => filled(env('KB_LIMIT_QUESTIONS_PER_USER')) ? (int) env('KB_LIMIT_QUESTIONS_PER_USER') : null,
        'questions_per_ip_per_day' => filled(env('KB_LIMIT_QUESTIONS_PER_IP')) ? (int) env('KB_LIMIT_QUESTIONS_PER_IP') : null, // see guests_per_ip_per_hour
        'guests_per_day' => filled(env('KB_LIMIT_GUESTS_PER_DAY')) ? (int) env('KB_LIMIT_GUESTS_PER_DAY') : null,
        // Per-visitor (IP) limits: only enable once the visitor IP is resolved correctly behind
        // your proxy (TRUSTED_PROXIES / KB_CLIENT_IP_HEADER), or every visitor shares one limit.
        'guests_per_ip_per_hour' => filled(env('KB_LIMIT_GUESTS_PER_IP_PER_HOUR')) ? (int) env('KB_LIMIT_GUESTS_PER_IP_PER_HOUR') : null,
        // Stored passages per account: bounds database growth from uploads (a 2 MB text file is ~2,000).
        'chunks_per_user' => filled(env('KB_LIMIT_CHUNKS_PER_USER')) ? (int) env('KB_LIMIT_CHUNKS_PER_USER') : null,
        'questions_per_day' => filled(env('KB_LIMIT_QUESTIONS_PER_DAY')) ? (int) env('KB_LIMIT_QUESTIONS_PER_DAY') : null,
        'documents_per_user' => filled(env('KB_LIMIT_DOCUMENTS_PER_USER')) ? (int) env('KB_LIMIT_DOCUMENTS_PER_USER') : null,
    ],

    'history' => [
        // Number of previous messages sent to the model as conversation context.
        'messages' => (int) env('KB_HISTORY_MESSAGES', 6),
    ],

];

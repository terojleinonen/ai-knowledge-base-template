<?php

namespace App\Services\Retrieval;

final readonly class SearchResult
{
    public function __construct(
        public int $chunkId,
        public int $documentId,
        public string $documentTitle,
        public string $content,
        public float $score,
    ) {}
}

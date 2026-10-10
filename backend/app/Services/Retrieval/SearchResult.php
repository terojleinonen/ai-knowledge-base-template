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
        public ?string $section = null,
    ) {}

    /** Title, section and content, as embedded and reranked. */
    public function contextualText(): string
    {
        return PassageText::of($this->documentTitle, $this->section, $this->content);
    }
}

<?php

namespace App\Services\Retrieval;

interface VectorStore
{
    /**
     * Find the chunks most similar to the query vector within a user's ready documents.
     *
     * @param  list<float>  $queryVector  unit-length query embedding
     * @param  list<int>|null  $documentIds  optionally restrict the search to these documents
     * @return list<SearchResult> ordered by descending score
     */
    public function search(int $userId, array $queryVector, string $embeddingModel, int $limit, float $minScore = 0.0, ?array $documentIds = null): array;
}

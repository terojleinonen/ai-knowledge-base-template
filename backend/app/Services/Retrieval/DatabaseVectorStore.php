<?php

namespace App\Services\Retrieval;

use App\Enums\DocumentStatus;
use App\Models\Chunk;
use Illuminate\Database\Eloquent\Builder;

/**
 * Brute-force cosine search over vectors stored in the relational database.
 *
 * Works on any database (including SQLite) and is fast enough for tens of
 * thousands of chunks per user. Swap in a pgvector / Qdrant implementation
 * of VectorStore when you outgrow it.
 */
class DatabaseVectorStore implements VectorStore
{
    public function search(int $userId, array $queryVector, string $embeddingModel, int $limit, float $minScore = 0.0, ?array $documentIds = null): array
    {
        $dimensions = count($queryVector);
        $scores = [];

        Chunk::query()
            ->select(['chunks.id', 'chunks.embedding'])
            ->where('chunks.user_id', $userId)
            ->whereHas('document', function (Builder $query) use ($embeddingModel) {
                $query->where('status', DocumentStatus::Ready)->where('embedding_model', $embeddingModel);
            })
            ->when($documentIds !== null, fn (Builder $q) => $q->whereIn('chunks.document_id', $documentIds))
            ->lazyById(500, 'chunks.id', 'id')
            ->each(function (Chunk $chunk) use ($queryVector, $dimensions, $minScore, &$scores) {
                $vector = VectorCodec::decode($chunk->embedding);

                if (count($vector) !== $dimensions) {
                    return;
                }

                $score = VectorCodec::dot($queryVector, $vector);

                if ($score >= $minScore) {
                    $scores[$chunk->id] = $score;
                }
            });

        arsort($scores);
        $scores = array_slice($scores, 0, $limit, preserve_keys: true);

        if ($scores === []) {
            return [];
        }

        $chunks = Chunk::query()
            ->with('document:id,title')
            ->whereIn('id', array_keys($scores))
            ->get(['id', 'document_id', 'section', 'content'])
            ->keyBy('id');

        $results = [];

        foreach ($scores as $chunkId => $score) {
            $chunk = $chunks[$chunkId];

            $results[] = new SearchResult(
                chunkId: $chunk->id,
                documentId: $chunk->document_id,
                documentTitle: $chunk->document->title,
                content: $chunk->content,
                score: round($score, 4),
                section: $chunk->section,
            );
        }

        return $results;
    }
}

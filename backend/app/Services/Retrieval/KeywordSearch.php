<?php

namespace App\Services\Retrieval;

use App\Enums\DocumentStatus;
use App\Models\Chunk;
use Illuminate\Database\Eloquent\Builder;

/**
 * BM25 keyword search over a user's ready chunks, computed in PHP in a single pass
 * (like DatabaseVectorStore, fine for thousands of chunks per user). Catches exact terms,
 * product names and numbers that embeddings can blur.
 */
class KeywordSearch
{
    private const K1 = 1.2;

    private const B = 0.75;

    /**
     * @param  list<int>|null  $documentIds
     * @return list<array{chunk_id: int, score: float, coverage: float}> by descending score;
     *                                                                   coverage = share of the query's distinctive (IDF-weighted) terms in the chunk
     */
    public function search(int $userId, string $query, string $embeddingModel, int $limit, ?array $documentIds = null): array
    {
        $queryTerms = array_values(array_unique(Tokenizer::tokens($query)));

        if ($queryTerms === []) {
            return [];
        }

        $wanted = array_flip($queryTerms);
        $chunkCount = 0;
        $totalLength = 0;
        $documentFrequency = array_fill_keys($queryTerms, 0);
        $matches = []; // chunk id => [length, [term => frequency]]

        Chunk::query()
            ->select(['chunks.id', 'chunks.content'])
            ->where('chunks.user_id', $userId)
            ->whereHas('document', fn (Builder $q) => $q->where('status', DocumentStatus::Ready)->where('embedding_model', $embeddingModel))
            ->when($documentIds !== null, fn (Builder $q) => $q->whereIn('chunks.document_id', $documentIds))
            ->lazyById(500, 'chunks.id', 'id')
            ->each(function (Chunk $chunk) use ($wanted, &$chunkCount, &$totalLength, &$documentFrequency, &$matches) {
                $tokens = Tokenizer::tokens($chunk->content);
                $chunkCount++;
                $totalLength += count($tokens);

                $frequencies = array_count_values(array_filter($tokens, fn (string $t) => isset($wanted[$t])));

                foreach (array_keys($frequencies) as $term) {
                    $documentFrequency[$term]++;
                }

                if ($frequencies !== []) {
                    $matches[$chunk->id] = [count($tokens), $frequencies];
                }
            });

        if ($matches === []) {
            return [];
        }

        $averageLength = $totalLength / max(1, $chunkCount);
        $idf = array_map(fn (int $df) => log(1 + ($chunkCount - $df + 0.5) / ($df + 0.5)), $documentFrequency);
        $totalIdf = array_sum($idf) ?: 1.0;
        $results = [];

        foreach ($matches as $chunkId => [$length, $frequencies]) {
            $score = 0.0;
            $matchedIdf = 0.0;

            foreach ($frequencies as $term => $frequency) {
                $score += $idf[$term] * ($frequency * (self::K1 + 1))
                    / ($frequency + self::K1 * (1 - self::B + self::B * $length / max(1, $averageLength)));
                $matchedIdf += $idf[$term];
            }

            $results[] = ['chunk_id' => $chunkId, 'score' => $score, 'coverage' => $matchedIdf / $totalIdf];
        }

        usort($results, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($results, 0, $limit);
    }
}

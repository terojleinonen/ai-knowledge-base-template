<?php

namespace App\Services\Retrieval;

use App\Models\Chunk;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Reranking;
use Throwable;

/**
 * Finds the passages that answer a question.
 *
 * Hybrid mode (default) merges vector search with BM25 keyword search using reciprocal
 * rank fusion, so exact terms ("IP54", "1Password") are found even when embeddings blur
 * them. If a reranker is configured, it orders the merged candidates and its relevance
 * score decides what is kept; otherwise a passage is kept when its vector score passes
 * the cutoff or it contains most of the question's distinctive terms. If the reranker
 * fails (outage, used-up free tokens), the non-reranked result is used.
 */
class Retriever
{
    /** Reciprocal rank fusion constant (Cormack et al.); damps the weight of top ranks. */
    private const RRF_K = 60;

    public function __construct(
        private readonly Embedder $embedder,
        private readonly VectorStore $store,
        private readonly KeywordSearch $keywords,
    ) {}

    /**
     * @param  list<int>|null  $documentIds
     * @return list<SearchResult> best first
     */
    public function retrieve(int $userId, string $question, ?array $documentIds = null): array
    {
        $topK = (int) config('knowledge.retrieval.top_k');
        $minScore = (float) config('knowledge.retrieval.min_score');
        $model = $this->embedder->identifier();
        $queryVector = $this->embedder->embedQuery($question);

        if (config('knowledge.retrieval.mode') === 'vector') {
            return $this->store->search($userId, $queryVector, $model, $topK, $minScore, $documentIds);
        }

        $candidates = max($topK, (int) config('knowledge.retrieval.candidates'));
        $vectorHits = $this->store->search($userId, $queryVector, $model, $candidates, 0.0, $documentIds);
        $keywordHits = $this->keywords->search($userId, $question, $model, $candidates, $documentIds);

        // Reciprocal rank fusion: each list contributes 1 / (k + rank).
        $fused = [];
        $results = [];
        $coverage = [];

        foreach ($vectorHits as $rank => $hit) {
            $fused[$hit->chunkId] = ($fused[$hit->chunkId] ?? 0) + 1 / (self::RRF_K + $rank + 1);
            $results[$hit->chunkId] = $hit;
        }

        foreach ($keywordHits as $rank => $hit) {
            $fused[$hit['chunk_id']] = ($fused[$hit['chunk_id']] ?? 0) + 1 / (self::RRF_K + $rank + 1);
            $coverage[$hit['chunk_id']] = $hit['coverage'];
        }

        if ($fused === []) {
            return [];
        }

        arsort($fused);
        $results += $this->load(array_diff_key($fused, $results), $queryVector);
        // A chunk deleted between the two searches has no result; skip it.
        $ordered = array_values(array_filter(array_map(fn (int $id) => $results[$id] ?? null, array_keys($fused))));

        if (config('knowledge.rerank.provider')) {
            $reranked = $this->rerank($question, array_slice($ordered, 0, (int) config('knowledge.rerank.candidates')));

            if ($reranked !== null) {
                return array_slice($reranked, 0, $topK);
            }
        }

        $kept = array_filter($ordered, fn (SearchResult $r) => $r->score >= $minScore
            || ($coverage[$r->chunkId] ?? 0) >= (float) config('knowledge.retrieval.keyword_min_coverage'));

        return array_slice(array_values($kept), 0, $topK);
    }

    /**
     * Order candidates by reranker relevance, dropping those below the configured minimum.
     * Returns null if the reranker is unavailable.
     *
     * @param  list<SearchResult>  $candidates
     * @return list<SearchResult>|null
     */
    private function rerank(string $question, array $candidates): ?array
    {
        if ($candidates === []) {
            return [];
        }

        try {
            $response = Reranking::of(array_map(fn (SearchResult $r) => $r->contextualText(), $candidates))
                ->limit(count($candidates))
                ->timeout((int) config('knowledge.rerank.timeout'))
                ->rerank($question, config('knowledge.rerank.provider'), config('knowledge.rerank.model'));
        } catch (Throwable $e) {
            Log::warning('Reranking failed; using hybrid ranking without it', ['error' => $e->getMessage()]);

            return null;
        }

        $min = config('knowledge.rerank.min_score');
        $reranked = [];

        foreach ($response->results as $ranked) {
            $candidate = $candidates[$ranked->index] ?? null;

            if ($candidate !== null && ($min === null || $ranked->score >= $min)) {
                $reranked[] = new SearchResult($candidate->chunkId, $candidate->documentId, $candidate->documentTitle, $candidate->content, round($ranked->score, 4), $candidate->section);
            }
        }

        usort($reranked, fn (SearchResult $a, SearchResult $b) => $b->score <=> $a->score);

        return $reranked;
    }

    /**
     * Search results for chunks found only by keyword search, with their exact vector score.
     *
     * @param  array<int, float>  $chunkIds  keyed by chunk id
     * @param  list<float>  $queryVector
     * @return array<int, SearchResult>
     */
    private function load(array $chunkIds, array $queryVector): array
    {
        if ($chunkIds === []) {
            return [];
        }

        return Chunk::query()
            ->with('document:id,title')
            ->whereIn('id', array_keys($chunkIds))
            ->get(['id', 'document_id', 'section', 'content', 'embedding'])
            ->mapWithKeys(function (Chunk $chunk) use ($queryVector) {
                $vector = VectorCodec::decode($chunk->embedding);
                $score = count($vector) === count($queryVector) ? VectorCodec::dot($queryVector, $vector) : 0.0;

                return [$chunk->id => new SearchResult(
                    chunkId: $chunk->id,
                    documentId: $chunk->document_id,
                    documentTitle: $chunk->document->title,
                    content: $chunk->content,
                    score: round($score, 4),
                    section: $chunk->section,
                )];
            })
            ->all();
    }
}

<?php

use App\Models\Chunk;
use App\Models\User;
use App\Services\Retrieval\Embedder;
use App\Services\Retrieval\KeywordSearch;
use App\Services\Retrieval\Retriever;
use App\Services\Retrieval\SearchResult;
use App\Services\Retrieval\VectorStore;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Prompts\RerankingPrompt;
use Laravel\Ai\Reranking;
use Laravel\Ai\Responses\Data\RankedDocument;

beforeEach(function () {
    Storage::fake('local');
    fakeKeywordEmbeddings();
    $this->user = User::factory()->create();
});

function retrieve(User $user, string $question, ?array $documentIds = null): array
{
    return app(Retriever::class)->retrieve($user->id, $question, $documentIds);
}

function model(): string
{
    return app(Embedder::class)->identifier();
}

/** @return list<string> */
function titles(array $results): array
{
    return array_map(fn (SearchResult $r) => $r->documentTitle, $results);
}

it('ranks chunks by BM25 and reports how much of the question they cover', function () {
    ingest($this->user, 'sensor', 'The X9 sensor is rated IP54. The X9 sensor runs on batteries.');
    ingest($this->user, 'gateway', 'The gateway connects sensors over LTE-M. It is rated IP54 too.');
    ingest($this->user, 'recipes', 'Banana bread needs flour, sugar and butter.');

    $hits = app(KeywordSearch::class)->search($this->user->id, 'Is X9 rated IP54?', model(), 10);
    $titles = array_map(fn ($h) => Chunk::find($h['chunk_id'])->document->title, $hits);

    expect($titles)->toBe(['sensor', 'gateway']) // x9 is rarer than ip54, and repeated
        ->and($hits[0]['coverage'])->toBe(1.0)
        ->and($hits[1]['coverage'])->toBeLessThan(0.5);
});

it('finds an exact term that vector search misses', function () {
    // The fake embeddings ignore words of 3 letters or fewer, so "X9" is invisible to vector search.
    ingest($this->user, 'sensor', 'Model X9 ships with a lithium battery.');
    ingest($this->user, 'recipes', 'Banana bread needs flour, sugar and butter.');

    config(['knowledge.retrieval.mode' => 'vector']);
    expect(retrieve($this->user, 'What is X9?'))->toBe([]);

    config(['knowledge.retrieval.mode' => 'hybrid']);
    $results = retrieve($this->user, 'What is X9?');

    expect(titles($results))->toBe(['sensor'])
        ->and($results[0]->score)->toBeLessThan(0.2); // its real (low) vector score is kept
});

it('drops keyword matches that cover too little of the question', function () {
    ingest($this->user, 'sensor', 'Model X9 ships with a lithium battery.');

    // "X9" matches, but "warranty" and "Q7" (the distinctive part) do not.
    expect(retrieve($this->user, 'Is X9 warranty like Q7?'))->toBe([]);
});

it('merges vector and keyword rankings with reciprocal rank fusion', function () {
    $hit = fn (int $id, float $score) => new SearchResult($id, 1, "doc{$id}", "content {$id}", $score);

    $store = Mockery::mock(VectorStore::class);
    $store->shouldReceive('search')->andReturn([$hit(1, 0.5), $hit(2, 0.4), $hit(3, 0.3)]);
    $keywords = Mockery::mock(KeywordSearch::class);
    $keywords->shouldReceive('search')->andReturn([
        ['chunk_id' => 3, 'score' => 4.0, 'coverage' => 1.0],
        ['chunk_id' => 2, 'score' => 2.0, 'coverage' => 0.4],
    ]);

    $results = (new Retriever(app(Embedder::class), $store, $keywords))->retrieve(1, 'question');

    expect(array_map(fn ($r) => $r->chunkId, $results))->toBe([3, 2, 1]);
});

it('keeps only the documents the question is limited to, and only its own user\'s', function () {
    $sensor = ingest($this->user, 'sensor', 'Model X9 ships with a lithium battery.');
    ingest($this->user, 'manual', 'Model X9 manual: charge the lithium battery overnight.');
    ingest(User::factory()->create(), 'other', 'Model X9 lithium battery recall notice.');

    expect(titles(retrieve($this->user, 'X9 lithium battery')))->toEqualCanonicalizing(['sensor', 'manual'])
        ->and(titles(retrieve($this->user, 'X9 lithium battery', [$sensor->id])))->toBe(['sensor']);
});

describe('with a reranker', function () {
    beforeEach(function () {
        config(['knowledge.rerank.provider' => 'jina', 'knowledge.rerank.model' => 'jina-reranker-v2-base-multilingual']);
        ingest($this->user, 'vacation', 'Vacation policy: employees receive twenty five vacation days.');
        ingest($this->user, 'carryover', 'Unused vacation days carry over until the end of March.');
    });

    it('orders passages by the reranker score', function () {
        Reranking::fake(fn (RerankingPrompt $prompt) => collect($prompt->documents)
            ->map(fn (string $doc, int $i) => new RankedDocument($i, $doc, str_contains($doc, 'carry') ? 0.9 : 0.3))
            ->sortByDesc('score')->values()->all());

        $results = retrieve($this->user, 'Do vacation days carry over?');

        expect(titles($results))->toBe(['carryover', 'vacation'])
            ->and(array_map(fn ($r) => $r->score, $results))->toBe([0.9, 0.3]);

        Reranking::assertReranked(fn (RerankingPrompt $prompt) => $prompt->query === 'Do vacation days carry over?'
            && $prompt->provider->name() === 'jina' && count($prompt->documents) === 2);
    });

    it('drops passages below the reranker threshold', function () {
        config(['knowledge.rerank.min_score' => 0.5]);
        Reranking::fake(fn (RerankingPrompt $prompt) => collect($prompt->documents)
            ->map(fn (string $doc, int $i) => new RankedDocument($i, $doc, str_contains($doc, 'carry') ? 0.9 : 0.1))
            ->all());

        expect(titles(retrieve($this->user, 'Do vacation days carry over?')))->toBe(['carryover']);
    });

    it('falls back to hybrid ranking when the reranker fails', function () {
        Log::spy();
        Reranking::fake(fn () => throw new RuntimeException('quota exceeded'));

        expect(titles(retrieve($this->user, 'Do vacation days carry over?')))->toContain('carryover');

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => str_contains($message, 'Reranking failed')
            && $context['error'] === 'quota exceeded');
    });

    it('is not called in vector mode', function () {
        config(['knowledge.retrieval.mode' => 'vector']);
        Reranking::fake();

        retrieve($this->user, 'Do vacation days carry over?');

        Reranking::assertNothingReranked();
    });
});

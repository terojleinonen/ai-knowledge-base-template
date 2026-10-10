<?php

use App\Services\Retrieval\Embedder;
use Laravel\Ai\Embeddings;

it('applies task prefixes to queries and documents', function () {
    fakeKeywordEmbeddings();
    $embedder = new Embedder('openai', 'text-embedding-3-small', queryPrefix: 'search_query: ', documentPrefix: 'search_document: ');

    $embedder->embedQuery('vacation days');
    $embedder->embedDocuments(['Vacation policy']);

    Embeddings::assertGenerated(fn ($prompt) => $prompt->inputs === ['search_query: vacation days']);
    Embeddings::assertGenerated(fn ($prompt) => $prompt->inputs === ['search_document: Vacation policy']);
});

it('includes prefixes in the identifier so changing them triggers reindexing', function () {
    $plain = new Embedder('ollama', 'nomic-embed-text');
    $prefixed = new Embedder('ollama', 'nomic-embed-text', queryPrefix: 'search_query: ', documentPrefix: 'search_document: ');

    expect($plain->identifier())->toBe('ollama:nomic-embed-text#'.Embedder::INDEX_FORMAT)
        ->and($prefixed->identifier())->toStartWith('ollama:nomic-embed-text#'.Embedder::INDEX_FORMAT.'+')
        ->and($prefixed->identifier())->not->toBe($plain->identifier());
});

it('embeds in batches and returns unit vectors in order', function () {
    fakeKeywordEmbeddings();
    $vectors = (new Embedder('openai', 'x', batchSize: 2))->embedDocuments(['alpha words', 'bravo words', 'charlie words']);

    expect($vectors)->toHaveCount(3);
    foreach ($vectors as $v) {
        expect(array_sum(array_map(fn ($x) => $x * $x, $v)))->toEqualWithDelta(1.0, 1e-9);
    }
    Embeddings::assertGenerated(fn ($prompt) => count($prompt->inputs) === 1);
});

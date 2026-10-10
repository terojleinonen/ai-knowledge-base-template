<?php

use App\Jobs\ProcessDocument;
use App\Models\Document;
use App\Services\Retrieval\Embedder;
use Illuminate\Support\Facades\Queue;

it('queues documents embedded with a different model or index format', function () {
    Queue::fake();

    $stale = Document::factory()->ready()->create(['embedding_model' => 'ollama:nomic-embed-text']);
    $oldFormat = Document::factory()->ready()->create(['embedding_model' => 'openai:text-embedding-3-small']); // before index format 2
    Document::factory()->ready()->create(['embedding_model' => app(Embedder::class)->identifier()]);

    $this->artisan('kb:reindex')->expectsOutputToContain('Queued 2 document(s)')->assertSuccessful();

    Queue::assertPushed(ProcessDocument::class, 2);
    Queue::assertPushed(ProcessDocument::class, fn ($job) => $job->document->is($stale));
    Queue::assertPushed(ProcessDocument::class, fn ($job) => $job->document->is($oldFormat));
});

it('can reindex everything', function () {
    Queue::fake();
    Document::factory()->ready()->count(2)->create(['embedding_model' => 'openai:text-embedding-3-small']);

    $this->artisan('kb:reindex --all')->assertSuccessful();

    Queue::assertPushed(ProcessDocument::class, 2);
});

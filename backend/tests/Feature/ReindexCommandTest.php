<?php

use App\Jobs\ProcessDocument;
use App\Models\Document;
use Illuminate\Support\Facades\Queue;

it('queues documents embedded with a different model', function () {
    Queue::fake();

    $stale = Document::factory()->ready()->create(['embedding_model' => 'ollama:nomic-embed-text']);
    Document::factory()->ready()->create(['embedding_model' => 'openai:text-embedding-3-small']);

    $this->artisan('kb:reindex')->expectsOutputToContain('Queued 1 document(s)')->assertSuccessful();

    Queue::assertPushed(ProcessDocument::class, 1);
    Queue::assertPushed(ProcessDocument::class, fn ($job) => $job->document->is($stale));
});

it('can reindex everything', function () {
    Queue::fake();
    Document::factory()->ready()->count(2)->create(['embedding_model' => 'openai:text-embedding-3-small']);

    $this->artisan('kb:reindex --all')->assertSuccessful();

    Queue::assertPushed(ProcessDocument::class, 2);
});

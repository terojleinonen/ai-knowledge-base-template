<?php

use App\Enums\DocumentStatus;
use App\Jobs\ProcessDocument;
use App\Models\Document;
use App\Models\User;
use App\Services\Chat\AnswerQuestion;
use App\Services\Retrieval\VectorCodec;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('local');
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
});

it('uploads a document and queues processing', function () {
    Queue::fake();

    $response = $this->postJson('/api/documents', [
        'file' => UploadedFile::fake()->createWithContent('handbook.txt', 'Employee handbook contents'),
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.title', 'handbook')
        ->assertJsonPath('data.status', 'pending');

    $document = Document::sole();
    Storage::disk('local')->assertExists($document->path);
    Queue::assertPushed(ProcessDocument::class, fn ($job) => $job->document->is($document));
});

it('rejects unsupported file types', function () {
    $this->postJson('/api/documents', [
        'file' => UploadedFile::fake()->create('malware.exe', 10),
    ])->assertUnprocessable()->assertJsonValidationErrors('file');
});

it('rejects duplicate uploads', function () {
    Queue::fake();
    $file = fn () => UploadedFile::fake()->createWithContent('a.txt', 'same content');

    $this->postJson('/api/documents', ['file' => $file()])->assertCreated();
    $this->postJson('/api/documents', ['file' => $file()])->assertUnprocessable()->assertJsonValidationErrors('file');
});

it('processes a document into embedded chunks', function () {
    fakeKeywordEmbeddings();
    config(['knowledge.chunking.size' => 200, 'knowledge.chunking.overlap' => 20]);

    $text = implode("\n\n", array_map(fn ($i) => "Section {$i}. ".str_repeat("Detail about topic {$i}. ", 8), range(1, 5)));

    $this->postJson('/api/documents', [
        'file' => UploadedFile::fake()->createWithContent('topics.md', $text),
    ])->assertCreated();

    $document = Document::sole();

    expect($document->status)->toBe(DocumentStatus::Ready)
        ->and($document->chunk_count)->toBeGreaterThan(1)
        ->and($document->chunks()->count())->toBe($document->chunk_count)
        ->and($document->embedding_model)->toBe('openai:text-embedding-3-small')
        ->and(VectorCodec::decode($document->chunks()->first()->embedding))->toHaveCount(64);

    Embeddings::assertGenerated(fn ($prompt) => str_contains($prompt->inputs[0], 'Section 1'));
});

it('marks documents without text as failed with a helpful error', function () {
    fakeKeywordEmbeddings();

    $this->postJson('/api/documents', [
        'file' => UploadedFile::fake()->createWithContent('empty.txt', "   \n\n  "),
    ])->assertCreated();

    expect(Document::sole())
        ->status->toBe(DocumentStatus::Failed)
        ->error->toContain('No text could be extracted');

    Embeddings::assertNothingGenerated();
});

it('explains when the embeddings provider is out of quota', function () {
    Embeddings::fake(fn () => throw InsufficientCreditsException::forProvider('openai', 429));
    Exceptions::fake();
    Storage::disk('local')->put('documents/notes.txt', 'Some meeting notes about the roadmap.');
    $document = Document::factory()->for($this->user)->create(['path' => 'documents/notes.txt']);

    // Tests run jobs synchronously, which rethrows after marking the job failed.
    expect(fn () => ProcessDocument::dispatchSync($document))->toThrow(InsufficientCreditsException::class);

    expect($document->refresh())
        ->status->toBe(DocumentStatus::Failed)
        ->error->toBe(AnswerQuestion::USAGE_LIMIT_MESSAGE);
});

it('lists only the current user\'s documents', function () {
    Document::factory()->for($this->user)->count(2)->create();
    Document::factory()->create();

    $this->getJson('/api/documents')->assertOk()->assertJsonCount(2, 'data');
});

it('filters documents by status', function () {
    Document::factory()->for($this->user)->ready()->create();
    Document::factory()->for($this->user)->create();

    $this->getJson('/api/documents?status=ready')->assertOk()->assertJsonCount(1, 'data');
});

it('hides other users\' documents', function () {
    $other = Document::factory()->create();

    $this->getJson("/api/documents/{$other->id}")->assertNotFound();
    $this->deleteJson("/api/documents/{$other->id}")->assertNotFound();
});

it('deletes a document with its chunks and file', function () {
    Storage::disk('local')->put('documents/x.txt', 'hello');
    $document = Document::factory()->for($this->user)->ready()->create(['path' => 'documents/x.txt']);
    $document->chunks()->create(['user_id' => $this->user->id, 'position' => 0, 'content' => 'hello', 'embedding' => VectorCodec::encode([1.0])]);

    $this->deleteJson("/api/documents/{$document->id}")->assertNoContent();

    expect(Document::count())->toBe(0);
    $this->assertDatabaseCount('chunks', 0);
    Storage::disk('local')->assertMissing('documents/x.txt');
});

it('reprocesses a failed document', function () {
    Queue::fake();
    $document = Document::factory()->for($this->user)->create(['status' => DocumentStatus::Failed, 'error' => 'boom']);

    $this->postJson("/api/documents/{$document->id}/reprocess")
        ->assertOk()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.error', null);

    Queue::assertPushed(ProcessDocument::class);
});

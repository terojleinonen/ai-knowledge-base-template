<?php

use App\Ai\Agents\KnowledgeBaseAssistant;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Chat\AnswerQuestion;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('local');
    fakeKeywordEmbeddings();

    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
});

it('answers a question using retrieved sources', function () {
    KnowledgeBaseAssistant::fake(['Employees get 25 vacation days per year [1].']);

    $handbook = ingest($this->user, 'handbook', 'Vacation policy: employees receive twenty five vacation days annually.');
    ingest($this->user, 'recipes', 'Banana bread needs flour, sugar, bananas and butter.');

    $response = $this->postJson('/api/chat', ['question' => 'How many vacation days do employees receive?']);

    $response->assertCreated()
        ->assertJsonPath('data.role', 'assistant')
        ->assertJsonPath('data.content', 'Employees get 25 vacation days per year [1].')
        ->assertJsonPath('data.sources.0.document_id', $handbook->id)
        ->assertJsonPath('data.sources.0.index', 1);

    KnowledgeBaseAssistant::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('Vacation policy')
        && $prompt->contains('Question: How many vacation days do employees receive?'));

    expect(Conversation::sole()->messages()->count())->toBe(2);
});

it('does not call the model when nothing relevant is found', function () {
    KnowledgeBaseAssistant::fake();
    ingest($this->user, 'recipes', 'Banana bread needs flour, sugar, bananas and butter.');

    $this->postJson('/api/chat', ['question' => 'Quarterly revenue figures?'])
        ->assertCreated()
        ->assertJsonPath('data.content', AnswerQuestion::NO_CONTEXT_ANSWER)
        ->assertJsonPath('data.sources', []);

    KnowledgeBaseAssistant::assertNeverPrompted();
});

it('never retrieves other users\' documents', function () {
    KnowledgeBaseAssistant::fake();
    ingest(User::factory()->create(), 'secret', 'Vacation policy: employees receive twenty five vacation days annually.');

    $this->postJson('/api/chat', ['question' => 'How many vacation days do employees receive?'])
        ->assertCreated()
        ->assertJsonPath('data.content', AnswerQuestion::NO_CONTEXT_ANSWER);

    KnowledgeBaseAssistant::assertNeverPrompted();
});

it('can restrict retrieval to selected documents', function () {
    KnowledgeBaseAssistant::fake(['ok']);
    ingest($this->user, 'handbook', 'Vacation policy: employees receive twenty five vacation days annually.');
    $other = ingest($this->user, 'handbook-2', 'Vacation policy for contractors: contractors receive no vacation days.');

    $this->postJson('/api/chat', ['question' => 'vacation days policy', 'document_ids' => [$other->id]])
        ->assertCreated()
        ->assertJsonCount(1, 'data.sources')
        ->assertJsonPath('data.sources.0.document_id', $other->id);
});

it('continues an existing conversation with history', function () {
    KnowledgeBaseAssistant::fake(['First answer [1].', 'Follow-up answer [1].']);
    ingest($this->user, 'handbook', 'Vacation policy: employees receive twenty five vacation days annually.');

    $first = $this->postJson('/api/chat', ['question' => 'How many vacation days?'])->assertCreated();
    $conversationId = $first->json('data.conversation_id');

    $this->postJson('/api/chat', ['question' => 'And vacation days for part-time employees?', 'conversation_id' => $conversationId])
        ->assertCreated()
        ->assertJsonPath('data.conversation_id', $conversationId);

    KnowledgeBaseAssistant::assertPrompted(fn (AgentPrompt $prompt) => collect($prompt->agent->messages())
        ->contains(fn ($m) => $m->content === 'First answer [1].'));

    $this->getJson("/api/conversations/{$conversationId}")
        ->assertOk()
        ->assertJsonCount(4, 'data.messages');
});

it('rejects another user\'s conversation', function () {
    $foreign = Conversation::factory()->create();

    $this->postJson('/api/chat', ['question' => 'Hello there', 'conversation_id' => $foreign->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('conversation_id');

    $this->getJson("/api/conversations/{$foreign->id}")->assertNotFound();
});

it('lists and deletes conversations', function () {
    $conversation = Conversation::factory()->for($this->user)->create();
    Conversation::factory()->create();

    $this->getJson('/api/conversations')->assertOk()->assertJsonCount(1, 'data');
    $this->deleteJson("/api/conversations/{$conversation->id}")->assertNoContent();
    $this->assertModelMissing($conversation);
});

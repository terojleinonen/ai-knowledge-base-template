<?php

use App\Ai\Agents\KnowledgeBaseAssistant;
use App\Enums\MessageRole;
use App\Jobs\ProcessDocument;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\Message;
use App\Models\User;
use App\Services\Chat\AnswerQuestion;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\Embeddings;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('local');
    fakeKeywordEmbeddings();

    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);

    $this->document = Document::factory()->for($this->user)->create(['title' => 'handbook', 'path' => 'documents/handbook.txt']);
    Storage::disk('local')->put($this->document->path, 'Vacation policy: employees receive twenty five vacation days annually.');
    ProcessDocument::dispatchSync($this->document);
});

/**
 * @return list<array{event: string, data: mixed}>
 */
function sseEvents(TestResponse $response): array
{
    preg_match_all('/^event: (\w+)\ndata: (.*)$/m', $response->streamedContent(), $matches, PREG_SET_ORDER);

    return array_map(fn ($m) => ['event' => $m[1], 'data' => json_decode($m[2], true)], $matches);
}

it('streams sources, text deltas and the saved message', function () {
    KnowledgeBaseAssistant::fake(['Employees get 25 vacation days [1].']);

    $response = $this->postJson('/api/chat/stream', ['question' => 'How many vacation days do employees receive?']);

    $response->assertOk()->assertHeader('Content-Type', 'text/event-stream; charset=utf-8');

    $events = sseEvents($response);
    $names = array_column($events, 'event');

    expect($names[0])->toBe('sources')
        ->and(end($names))->toBe('done')
        ->and(array_count_values($names)['delta'])->toBeGreaterThan(1)
        ->and($events[0]['data']['sources'][0]['document_id'])->toBe($this->document->id);

    $text = collect($events)->where('event', 'delta')->pluck('data.text')->implode('');
    expect($text)->toBe('Employees get 25 vacation days [1].');

    $done = end($events)['data'];
    expect($done['content'])->toBe($text)
        ->and($done['sources'])->toHaveCount(1)
        ->and(Message::find($done['id'])->conversation_id)->toBe($done['conversation_id'])
        ->and(Conversation::sole()->messages()->count())->toBe(2);
});

it('streams the fallback answer without calling the model when nothing matches', function () {
    KnowledgeBaseAssistant::fake();

    $events = sseEvents($this->postJson('/api/chat/stream', ['question' => 'Quarterly revenue figures?']));

    expect(array_column($events, 'event'))->toBe(['sources', 'delta', 'done'])
        ->and($events[1]['data']['text'])->toBe(AnswerQuestion::NO_CONTEXT_ANSWER);

    KnowledgeBaseAssistant::assertNeverPrompted();
});

it('emits an error event and persists nothing when the provider fails', function () {
    Exceptions::fake();
    KnowledgeBaseAssistant::fake(fn () => throw new RuntimeException('provider down'));

    $events = sseEvents($this->postJson('/api/chat/stream', ['question' => 'How many vacation days do employees receive?']));

    expect(array_column($events, 'event'))->toBe(['sources', 'error'])
        ->and($events[1]['data']['message'])->not->toContain('provider down');

    expect(Conversation::count())->toBe(0);
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'provider down');
});

it('emits an error event when retrieval fails', function () {
    Exceptions::fake();
    Embeddings::fake(fn () => throw new RuntimeException('embeddings down'));

    $events = sseEvents($this->postJson('/api/chat/stream', ['question' => 'How many vacation days?']));

    expect(array_column($events, 'event'))->toBe(['error']);
    Exceptions::assertReported(RuntimeException::class);
});

it('keeps the partial answer when the client disconnects mid-stream', function () {
    KnowledgeBaseAssistant::fake(['Employees get twenty five vacation days per year [1].']);

    $stream = app(AnswerQuestion::class)->stream($this->user, 'How many vacation days do employees receive?');

    $stream->current();           // sources
    $stream->next();              // first delta
    $stream->next();              // second delta
    unset($stream);               // client went away

    $assistant = Message::where('role', MessageRole::Assistant)->sole();

    expect($assistant->content)->toBe('Employees get')
        ->and($assistant->sources)->toHaveCount(1);
});

it('validates input before streaming', function () {
    $foreign = Conversation::factory()->create();

    $this->postJson('/api/chat/stream', ['question' => 'Hello there', 'conversation_id' => $foreign->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('conversation_id');
});

<?php

use App\Ai\Agents\KnowledgeBaseAssistant;
use App\Jobs\ProcessDocument;
use App\Models\Document;
use App\Models\Message;
use App\Models\User;
use App\Services\Chat\AnswerQuestion;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\Enums\Lab;
use Laravel\Sanctum\Sanctum;

// Exercises laravel/ai's real Anthropic request building and response parsing
// against a faked Messages API, rather than the agent fake.

beforeEach(function () {
    Storage::fake('local');
    fakeKeywordEmbeddings();

    config([
        'knowledge.chat.provider' => 'anthropic',
        'knowledge.chat.model' => 'claude-opus-5-5',
        'knowledge.chat.effort' => 'medium',
        'knowledge.chat.fallbacks' => 'default',
        'ai.providers.anthropic.key' => 'test-key',
    ]);

    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);

    $document = Document::factory()->for($this->user)->create(['title' => 'handbook', 'path' => 'documents/handbook.txt']);
    Storage::disk('local')->put($document->path, 'Vacation policy: employees receive twenty five vacation days annually.');
    ProcessDocument::dispatchSync($document);
});

/**
 * @param  list<array<string, mixed>>  $content
 */
function anthropicMessage(array $content, string $stopReason = 'end_turn', string $model = 'claude-opus-5-5'): array
{
    return [
        'id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => $model,
        'content' => $content, 'stop_reason' => $stopReason, 'stop_sequence' => null,
        'usage' => ['input_tokens' => 100, 'output_tokens' => 20],
    ];
}

/**
 * @param  list<array{0: string, 1: array<string, mixed>}>  $events
 */
function anthropicStream(array $events): string
{
    return implode('', array_map(fn ($e) => "event: {$e[0]}\ndata: ".json_encode($e[1])."\n\n", $events));
}

function sseEventsOf(TestResponse $response): array
{
    preg_match_all('/^event: (\w+)\ndata: (.*)$/m', $response->streamedContent(), $m, PREG_SET_ORDER);

    return array_map(fn ($x) => ['event' => $x[1], 'data' => json_decode($x[2], true)], $m);
}

const QUESTION = 'How many vacation days do employees receive?';

it('sends effort, server-side fallbacks and the beta header to Anthropic', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicMessage([['type' => 'text', 'text' => 'Employees receive twenty five vacation days annually [1].']]))]);

    $this->postJson('/api/chat', ['question' => QUESTION])
        ->assertCreated()
        ->assertJsonPath('data.content', 'Employees receive twenty five vacation days annually [1].');

    Http::assertSent(function (Request $request) {
        return str_ends_with($request->url(), '/messages')
            && $request['model'] === 'claude-opus-5-5'
            && $request['fallbacks'] === 'default'
            && $request['output_config'] === ['effort' => 'medium']
            && ! isset($request['temperature'])
            && str_contains($request->header('anthropic-beta')[0] ?? '', 'server-side-fallback-2026-07-01');
    });
});

it('uses the fallback model\'s answer when Anthropic falls back', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicMessage([
        ['type' => 'fallback', 'from' => ['model' => 'claude-opus-5-5'], 'to' => ['model' => 'claude-opus-5']],
        ['type' => 'text', 'text' => 'Employees receive twenty five vacation days annually [1].'],
    ], model: 'claude-opus-5'))]);

    $this->postJson('/api/chat', ['question' => QUESTION])
        ->assertCreated()
        ->assertJsonPath('data.content', 'Employees receive twenty five vacation days annually [1].');
});

it('replaces a refusal with a clear message', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicMessage([], 'refusal'))]);

    $this->postJson('/api/chat', ['question' => QUESTION])
        ->assertCreated()
        ->assertJsonPath('data.content', AnswerQuestion::REFUSED_ANSWER);
});

it('discards partial streamed text when the model declines mid-output', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicStream([
        ['message_start', ['type' => 'message_start', 'message' => anthropicMessage([], 'end_turn') + ['stop_reason' => null]]],
        ['content_block_start', ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]],
        ['content_block_delta', ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Employees receive']]],
        ['content_block_stop', ['type' => 'content_block_stop', 'index' => 0]],
        ['message_delta', ['type' => 'message_delta', 'delta' => ['stop_reason' => 'refusal'], 'usage' => ['output_tokens' => 3]]],
        ['message_stop', ['type' => 'message_stop']],
    ]), 200, ['Content-Type' => 'text/event-stream'])]);

    $events = sseEventsOf($this->postJson('/api/chat/stream', ['question' => QUESTION]));
    $done = end($events);

    expect(collect($events)->where('event', 'delta')->pluck('data.text')->implode(''))->toBe('Employees receive')
        ->and($done['event'])->toBe('done')
        ->and($done['data']['content'])->toBe(AnswerQuestion::REFUSED_ANSWER)
        ->and(Message::where('role', 'assistant')->sole()->content)->toBe(AnswerQuestion::REFUSED_ANSWER);
});

it('streams a normal answer through the real gateway', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicStream([
        ['message_start', ['type' => 'message_start', 'message' => anthropicMessage([], 'end_turn') + ['stop_reason' => null]]],
        ['content_block_start', ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]],
        ['content_block_delta', ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Employees receive twenty five ']]],
        ['content_block_delta', ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'vacation days annually [1].']]],
        ['content_block_stop', ['type' => 'content_block_stop', 'index' => 0]],
        ['message_delta', ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 12]]],
        ['message_stop', ['type' => 'message_stop']],
    ]), 200, ['Content-Type' => 'text/event-stream'])]);

    $events = sseEventsOf($this->postJson('/api/chat/stream', ['question' => QUESTION]));

    expect(end($events)['data']['content'])->toBe('Employees receive twenty five vacation days annually [1].');
});

it('sends no Anthropic-only options to other providers or when unset', function () {
    $agent = new KnowledgeBaseAssistant;

    expect($agent->providerOptions(Lab::OpenAI))->toBe([])
        ->and($agent->providerOptions('ollama'))->toBe([])
        ->and($agent->providerOptions(Lab::Anthropic))->toBe(['fallbacks' => 'default', 'output_config' => ['effort' => 'medium']]);

    config(['knowledge.chat.effort' => null, 'knowledge.chat.fallbacks' => null]);
    expect($agent->providerOptions(Lab::Anthropic))->toBe([]);
});

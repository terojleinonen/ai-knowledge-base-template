<?php

use App\Ai\Agents\KnowledgeBaseAssistant;
use App\Ai\Agents\QueryRewriter;
use App\Models\User;
use App\Services\Chat\AnswerQuestion;
use App\Services\Chat\StandaloneQuestion;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('local');
    fakeKeywordEmbeddings();

    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);

    ingest($this->user, 'security', 'Password manager: the approved password manager is 1Password. Passwords must be at least sixteen characters long.');
    ingest($this->user, 'aurora', 'Aurora sensor battery lasts five years. The Aurora sensor costs 149 euros.');
});

/** Ask a question, then a follow-up in the same conversation; returns the follow-up's response. */
function askFollowUp(object $test, string $first, string $followUp)
{
    $conversationId = $test->postJson('/api/chat', ['question' => $first])->json('data.conversation_id');

    return $test->postJson('/api/chat', ['question' => $followUp, 'conversation_id' => $conversationId]);
}

it('searches with a standalone rewrite of a follow-up question', function () {
    config(['knowledge.follow_ups.mode' => 'rewrite']);
    KnowledgeBaseAssistant::fake(['The approved password manager is 1Password [1].', 'At least sixteen characters [1].']);
    QueryRewriter::fake(['"How long must passwords be?"']);

    askFollowUp($this, 'Which password manager is approved?', 'How long must they be?')
        ->assertCreated()
        ->assertJsonPath('data.content', 'At least sixteen characters [1].')
        ->assertJsonPath('data.sources.0.document_title', 'security');

    // The rewriter saw the conversation (answer without citation markers) and the follow-up.
    QueryRewriter::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('User: Which password manager is approved?')
        && $prompt->contains('Assistant: The approved password manager is 1Password.')
        && $prompt->contains('Latest message: How long must they be?'));
    QueryRewriter::assertPromptedTimes(1);

    // The chat model still gets the question as the user typed it.
    KnowledgeBaseAssistant::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('Question: How long must they be?'));
});

it('does not rewrite the first question of a conversation', function () {
    config(['knowledge.follow_ups.mode' => 'rewrite']);
    KnowledgeBaseAssistant::fake(['1Password [1].']);
    QueryRewriter::fake();

    $this->postJson('/api/chat', ['question' => 'Which password manager is approved?'])->assertCreated();

    QueryRewriter::assertNeverPrompted();
});

it('combines a follow-up with the previous question when the rewrite fails', function () {
    config(['knowledge.follow_ups.mode' => 'rewrite']);
    Log::spy();
    QueryRewriter::fake(fn () => throw new RuntimeException('overloaded'));

    $history = [new UserMessage('Which password manager is approved?'), new AssistantMessage('1Password [1].')];

    expect(app(StandaloneQuestion::class)->for('How long must they be?', $history))
        ->toBe("Which password manager is approved?\nHow long must they be?");

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'follow-up'));
});

it('combines when the rewrite is empty or not a query', function (string $output) {
    config(['knowledge.follow_ups.mode' => 'rewrite']);
    QueryRewriter::fake([$output]);

    expect(app(StandaloneQuestion::class)->for('And the price?', [new UserMessage('How long does the Aurora battery last?')]))
        ->toBe("How long does the Aurora battery last?\nAnd the price?");
})->with(['empty' => '  ', 'too long' => str_repeat('word ', 200)]);

it('combines without a model call in combine mode, and searches as typed when off', function () {
    QueryRewriter::fake();
    $history = [new UserMessage('How long does the Aurora battery last?'), new AssistantMessage('Five years [1].')];

    config(['knowledge.follow_ups.mode' => 'combine']);
    expect(app(StandaloneQuestion::class)->for('And the price?', $history))->toBe("How long does the Aurora battery last?\nAnd the price?");

    config(['knowledge.follow_ups.mode' => 'off']);
    expect(app(StandaloneQuestion::class)->for('And the price?', $history))->toBe('And the price?');

    QueryRewriter::assertNeverPrompted();
});

it('finds the sources for a vague follow-up only with conversation context', function () {
    KnowledgeBaseAssistant::fake(['Five years [1].', 'It costs 149 euros [1].', 'Five years [1].', 'Not found.']);

    config(['knowledge.follow_ups.mode' => 'combine']);
    askFollowUp($this, 'How long does the Aurora battery last?', 'Is that good?')
        ->assertJsonPath('data.sources.0.document_title', 'aurora');

    config(['knowledge.follow_ups.mode' => 'off']);
    askFollowUp($this, 'How long does the Aurora battery last?', 'Is that good?')
        ->assertJsonPath('data.content', AnswerQuestion::NO_CONTEXT_ANSWER);
});

it('uses the follow-up model when one is configured', function () {
    config(['knowledge.follow_ups.mode' => 'rewrite', 'knowledge.follow_ups.provider' => 'anthropic', 'knowledge.follow_ups.model' => 'claude-haiku-5-5']);
    QueryRewriter::fake(['What does the Aurora sensor cost?']);

    app(StandaloneQuestion::class)->for('And the price?', [new UserMessage('How long does the Aurora battery last?')]);

    QueryRewriter::assertPrompted(fn (AgentPrompt $prompt) => $prompt->model === 'claude-haiku-5-5');
});

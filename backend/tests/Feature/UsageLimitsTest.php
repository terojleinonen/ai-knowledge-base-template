<?php

use App\Ai\Agents\KnowledgeBaseAssistant;
use App\Models\Document;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    fakeKeywordEmbeddings();
    KnowledgeBaseAssistant::fake();
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
});

it('caps questions per user per day on both chat endpoints', function () {
    config(['knowledge.limits.questions_per_user_per_day' => 2]);

    $this->postJson('/api/chat', ['question' => 'First question?'])->assertCreated();
    $this->postJson('/api/chat/stream', ['question' => 'Second question?'])->assertOk();

    $this->postJson('/api/chat', ['question' => 'Third question?'])
        ->assertTooManyRequests()
        ->assertJsonPath('message', "You've reached today's limit of 2 questions. Please come back tomorrow.");
    $this->postJson('/api/chat/stream', ['question' => 'Third question?'])->assertTooManyRequests();

    // Other users are unaffected, and the limit resets the next day.
    Sanctum::actingAs(User::factory()->create());
    $this->postJson('/api/chat', ['question' => 'Other user?'])->assertCreated();

    Sanctum::actingAs($this->user);
    $this->travel(1)->day();
    $this->postJson('/api/chat', ['question' => 'Tomorrow?'])->assertCreated();
});

it('caps questions across all users per day', function () {
    config(['knowledge.limits.questions_per_day' => 1]);

    $this->postJson('/api/chat', ['question' => 'First question?'])->assertCreated();

    Sanctum::actingAs(User::factory()->create());
    $this->postJson('/api/chat', ['question' => 'Another question?'])
        ->assertTooManyRequests()
        ->assertJsonPath('message', 'The demo has reached its daily question limit. Please come back tomorrow.');
});

it('does not count invalid requests', function () {
    config(['knowledge.limits.questions_per_user_per_day' => 1]);

    $this->postJson('/api/chat', ['question' => ''])->assertUnprocessable();
    $this->postJson('/api/chat', ['question' => 'Valid question?'])->assertCreated();
});

it('caps documents per user', function () {
    Storage::fake('local');
    Queue::fake();
    config(['knowledge.limits.documents_per_user' => 1]);
    Document::factory()->for($this->user)->create();

    $this->postJson('/api/documents', ['file' => UploadedFile::fake()->createWithContent('b.txt', 'more')])
        ->assertUnprocessable()
        ->assertJsonPath('errors.file.0', 'You have reached the limit of 1 documents. Delete one to upload another.');
});

it('can disable registration', function () {
    config(['knowledge.registration.enabled' => false]);

    $this->postJson('/api/auth/register', [
        'name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'secret-password', 'password_confirmation' => 'secret-password',
    ])->assertForbidden()->assertJsonPath('message', 'Registration is disabled. Try the demo instead.');
});

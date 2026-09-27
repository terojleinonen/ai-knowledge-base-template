<?php

use App\Ai\Agents\KnowledgeBaseAssistant;
use App\Models\Document;
use App\Models\User;
use App\Services\Demo\DemoAccounts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Embeddings;

beforeEach(function () {
    Storage::fake('local');
    fakeKeywordEmbeddings();
    config([
        'knowledge.demo.enabled' => true,
        'knowledge.demo.dataset' => base_path('tests/Fixtures/demo'),
    ]);
});

function guestToken(): string
{
    return test()->postJson('/api/auth/guest')->assertCreated()->json('token');
}

it('creates a guest with a private copy of the demo documents', function () {
    $response = $this->postJson('/api/auth/guest')->assertCreated()->assertJsonPath('user.is_guest', true);

    $guest = User::where('is_guest', true)->sole();
    $template = User::where('email', DemoAccounts::TEMPLATE_EMAIL)->sole();

    expect($guest->documents()->pluck('title')->sort()->values()->all())->toBe(['security', 'vacation'])
        ->and($guest->documents()->sum('chunk_count'))->toBe($template->documents()->sum('chunk_count'))
        ->and(DB::table('chunks')->where('user_id', $guest->id)->count())->toBeGreaterThan(0);

    $this->withToken($response->json('token'))->getJson('/api/documents')->assertOk()->assertJsonCount(2, 'data');
});

it('does not re-ingest when the template is up to date', function () {
    $calls = 0;
    Embeddings::fake(function ($prompt) use (&$calls) {
        $calls++;

        return array_map(fn (string $text) => keywordVector($text), $prompt->inputs);
    });

    $demo = app(DemoAccounts::class);

    expect($demo->prepare())->toBe(2)->and($calls)->toBe(2);

    expect($demo->prepare())->toBe(0);
    $demo->createGuest();
    $demo->createGuest();

    expect($calls)->toBe(2);
});

it('lets guests ask questions over the demo documents', function () {
    KnowledgeBaseAssistant::fake(['Employees receive thirty vacation days annually [1].']);

    $this->withToken(guestToken())
        ->postJson('/api/chat', ['question' => 'How many vacation days do employees receive annually?'])
        ->assertCreated()
        ->assertJsonPath('data.sources.0.document_title', 'vacation');
});

it('keeps guests isolated from each other', function () {
    $first = guestToken();
    $second = guestToken();

    $firstDoc = $this->withToken($first)->getJson('/api/documents')->json('data.0.id');

    // The test client caches the authenticated user between requests; reset it.
    app('auth')->forgetGuards();

    $this->withToken($second)->getJson("/api/documents/{$firstDoc}")->assertNotFound();
    $this->withToken($second)->getJson('/api/documents')->assertJsonMissing(['id' => $firstDoc]);
});

it('keeps the shared file when a guest deletes a demo document', function () {
    $token = guestToken();
    $document = Document::whereHas('user', fn ($q) => $q->where('is_guest', true))->firstOrFail();

    $this->withToken($token)->deleteJson("/api/documents/{$document->id}")->assertNoContent();

    Storage::disk('local')->assertExists($document->path);
});

it('prunes expired guests with their tokens but keeps the template and files', function () {
    guestToken();
    $this->travel(25)->hours();
    guestToken();

    $this->artisan('kb:demo:prune')->expectsOutputToContain('Deleted 1 guest account(s).')->assertSuccessful();

    expect(User::where('is_guest', true)->count())->toBe(1)
        ->and(User::where('email', DemoAccounts::TEMPLATE_EMAIL)->exists())->toBeTrue()
        ->and(DB::table('personal_access_tokens')->count())->toBe(1);

    foreach (Document::all() as $document) {
        Storage::disk('local')->assertExists($document->path);
    }
});

it('returns 404 for guest sessions when the demo is disabled', function () {
    config(['knowledge.demo.enabled' => false]);

    $this->postJson('/api/auth/guest')->assertNotFound();
});

it('exposes public config for the frontend', function () {
    config(['knowledge.registration.enabled' => false, 'knowledge.limits.questions_per_user_per_day' => 20]);

    $this->getJson('/api/config')->assertOk()->assertJson([
        'demo' => true,
        'registration' => false,
        'limits' => ['questions_per_user_per_day' => 20],
    ]);
});

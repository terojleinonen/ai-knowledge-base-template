<?php

use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

const SITEVERIFY = 'challenges.cloudflare.com/turnstile/v0/siteverify';

beforeEach(function () {
    Storage::fake('local');
    fakeKeywordEmbeddings();
    config([
        'knowledge.demo.enabled' => true,
        'knowledge.demo.dataset' => base_path('tests/Fixtures/demo'),
        'knowledge.turnstile.site_key' => 'site-key',
        'knowledge.turnstile.secret_key' => 'secret-key',
        'knowledge.turnstile.monitor_token' => 'monitor-secret',
    ]);
});

function siteverify(array $response, int $status = 200): void
{
    Http::fake([SITEVERIFY => Http::response($response, $status)]);
}

it('starts a demo session with a valid token for the guest action', function () {
    siteverify(['success' => true, 'action' => 'guest', 'hostname' => 'example.pages.dev']);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
        ->postJson('/api/auth/guest', ['turnstile_token' => 'valid-token'])
        ->assertCreated();

    Http::assertSent(fn (Request $r) => str_contains($r->url(), SITEVERIFY)
        && $r['secret'] === 'secret-key' && $r['response'] === 'valid-token' && $r['remoteip'] === '203.0.113.7'
        && filled($r['idempotency_key']));
});

it('rejects missing, failed and wrong-action tokens', function (?string $token, ?array $response) {
    $response ? siteverify($response) : Http::fake();

    $this->postJson('/api/auth/guest', array_filter(['turnstile_token' => $token]))
        ->assertUnprocessable()
        ->assertJsonPath('errors.turnstile_token.0', 'Please complete the human check and try again.');

    expect(User::where('is_guest', true)->count())->toBe(0);
    if ($response === null) {
        Http::assertNothingSent();
    }
})->with([
    'missing token' => [null, null],
    'failed check' => ['bad-token', ['success' => false, 'error-codes' => ['invalid-input-response']]],
    'replayed token' => ['old-token', ['success' => false, 'error-codes' => ['timeout-or-duplicate']]],
    'token for another action' => ['other-token', ['success' => true, 'action' => 'login']],
]);

it('lets requests through when Cloudflare is unreachable, and logs it', function () {
    Log::spy();
    Http::fake([SITEVERIFY => Http::response('down', 503)]);

    $this->postJson('/api/auth/guest', ['turnstile_token' => 'any-token'])->assertCreated();

    Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'Turnstile verification unavailable'));
    Http::assertSentCount(2); // one retry
});

it('lets monitoring start sessions with the monitor token, and only with the right one', function () {
    Http::fake();

    $this->withHeader('X-Monitor-Token', 'monitor-secret')->postJson('/api/auth/guest')->assertCreated();
    Http::assertNothingSent();

    $this->withHeader('X-Monitor-Token', 'wrong')->postJson('/api/auth/guest')->assertUnprocessable();
});

it('does not count failed checks against the daily session cap', function () {
    config(['knowledge.limits.guests_per_day' => 1]);
    Http::fake([SITEVERIFY => Http::sequence()
        ->push(['success' => false])
        ->push(['success' => true, 'action' => 'guest'])]);

    $this->postJson('/api/auth/guest', ['turnstile_token' => 'bad'])->assertUnprocessable();
    $this->postJson('/api/auth/guest', ['turnstile_token' => 'good'])->assertCreated();
});

it('is skipped entirely when no secret key is configured', function () {
    config(['knowledge.turnstile.secret_key' => null]);
    Http::fake();

    $this->postJson('/api/auth/guest')->assertCreated();
    $this->getJson('/api/config')->assertJsonPath('turnstile_site_key', null);
    Http::assertNothingSent();
});

it('exposes the site key to the frontend when enabled', function () {
    $this->getJson('/api/config')->assertJsonPath('turnstile_site_key', 'site-key');
});

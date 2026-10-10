<?php

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('local');
    fakeKeywordEmbeddings();
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
});

/** Short numbered paragraphs, so chunks carry several of them over as overlap. */
function longText(): string
{
    return collect(range(1, 60))
        ->map(fn (int $n) => "Rule {$n}: employees must follow guideline number {$n} every day.")
        ->implode("\n\n");
}

it('returns a cited passage whole, with the surrounding text and no repeated overlap', function () {
    config(['knowledge.chunking.size' => 600, 'knowledge.chunking.overlap' => 200]);
    $document = ingest($this->user, 'rules', longText());
    $chunks = $document->chunks()->orderBy('position')->get();
    expect($chunks->count())->toBeGreaterThan(5);
    $cited = $chunks[3];

    $data = $this->getJson("/api/passages/{$cited->id}")->assertOk()->json('data');

    expect($data['document'])->toBe(['id' => $document->id, 'title' => 'rules'])
        ->and($data['content'])->toBe($cited->content)
        ->and($data['position'])->toBe(3)
        ->and($data['total'])->toBe($chunks->count())
        ->and($data['before'])->toHaveCount(2)
        ->and($data['after'])->toHaveCount(2);

    // Read in order, the text is the original document without duplicated overlap.
    $joined = implode(' ', [...$data['before'], $data['content'], ...$data['after']]);
    $original = implode(' ', array_map(fn ($c) => trim($c), $chunks->slice(1, 5)->pluck('content')->all()));
    $normalize = fn (string $s) => preg_replace('/\s+/', ' ', $s);

    expect(str_contains($normalize(longText()), $normalize($joined)))->toBeTrue()
        ->and(mb_strlen($joined))->toBeLessThan(mb_strlen($original));
});

it('handles passages at the start and end of a document', function () {
    config(['knowledge.chunking.size' => 600, 'knowledge.chunking.overlap' => 200]);
    $chunks = ingest($this->user, 'rules', longText())->chunks()->orderBy('position')->get();

    $first = $this->getJson("/api/passages/{$chunks->first()->id}")->json('data');
    $last = $this->getJson("/api/passages/{$chunks->last()->id}")->json('data');

    expect($first['before'])->toBe([])->and($first['after'])->toHaveCount(2)
        ->and($last['after'])->toBe([])->and($last['before'])->toHaveCount(2);
});

it('keeps neighbours whole when chunks do not overlap', function () {
    config(['knowledge.chunking.size' => 600, 'knowledge.chunking.overlap' => 0]);
    $chunks = ingest($this->user, 'rules', longText())->chunks()->orderBy('position')->get();

    $data = $this->getJson("/api/passages/{$chunks[1]->id}")->json('data');

    expect($data['before'])->toBe([$chunks[0]->content])->and($data['after'][0])->toBe($chunks[2]->content);
});

it('does not show another user\'s passages', function () {
    $foreign = ingest(User::factory()->create(), 'secret', 'Top secret plans.')->chunks()->first();

    $this->getJson("/api/passages/{$foreign->id}")->assertNotFound();
    $this->getJson('/api/passages/999999')->assertNotFound();
});

it('requires authentication', function () {
    app('auth')->forgetGuards();
    $this->withHeaders(['Authorization' => ''])->getJson('/api/passages/1')->assertUnauthorized();
});

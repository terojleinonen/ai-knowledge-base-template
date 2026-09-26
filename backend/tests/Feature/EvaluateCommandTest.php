<?php

use App\Ai\Agents\KnowledgeBaseAssistant;
use App\Evaluation\EvalRunner;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    fakeKeywordEmbeddings();
    $this->dataset = base_path('tests/Fixtures/eval');
});

it('evaluates retrieval and answers, then cleans up', function () {
    KnowledgeBaseAssistant::fake([
        'Employees receive thirty vacation days annually [2].', // wrong number, repaired to [1]
        'The approved password manager is Bitwarden [1].',      // wrong fact
    ]);

    $json = tempnam(sys_get_temp_dir(), 'eval');

    $this->artisan('kb:eval', ['dataset' => $this->dataset, '--json' => $json])
        ->expectsOutputToContain('Evaluating "Fixture": 2 documents, 3 cases')
        ->expectsOutputToContain('missing: 1Password')
        ->assertSuccessful();

    $report = json_decode(file_get_contents($json), true);

    expect($report['summary'])->toMatchArray([
        'cases' => 3,
        'hit_at_1' => 1.0,
        'off_topic_filtered' => 1.0,
        'fact_recall' => 0.5,
        'citation_precision' => 1.0,
        'correct_abstentions' => 1.0,
        'passed' => 2,
        'judged' => 3,
    ])->and($report['cases'][0]['answer'])->toBe('Employees receive thirty vacation days annually [1].')
        ->and($report['cases'][1]['passed'])->toBeFalse()
        ->and($report['settings']['repair'])->toBe('on');

    // The throwaway user, its documents and stored files are gone.
    expect(User::count())->toBe(0)->and(Document::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);

    unlink($json);
});

it('measures raw citations with --no-repair', function () {
    KnowledgeBaseAssistant::fake(['Employees receive thirty vacation days annually [2].']);
    $json = tempnam(sys_get_temp_dir(), 'eval');

    $this->artisan('kb:eval', ['dataset' => $this->dataset, '--no-repair' => true, '--limit' => 1, '--json' => $json])
        ->assertSuccessful();

    $report = json_decode(file_get_contents($json), true);

    expect($report['summary']['citation_precision'])->toBe(0.0)
        ->and($report['settings']['repair'])->toBe('off')
        ->and($report['cases'])->toHaveCount(1);

    unlink($json);
});

it('fails answerable cases that cite nothing', function () {
    KnowledgeBaseAssistant::fake(['Overall, employees receive thirty vacation days annually.']);
    config(['knowledge.citations.repair' => false]);
    $json = tempnam(sys_get_temp_dir(), 'eval');

    $this->artisan('kb:eval', ['dataset' => $this->dataset, '--limit' => 1, '--json' => $json])->assertSuccessful();

    $report = json_decode(file_get_contents($json), true);

    expect($report['cases'][0]['facts_found'])->toBe(1)
        ->and($report['cases'][0]['passed'])->toBeFalse()
        ->and($report['summary']['uncited_answers'])->toBe(1);

    unlink($json);
});

it('skips the chat model with --retrieval-only', function () {
    KnowledgeBaseAssistant::fake();

    $this->artisan('kb:eval', ['dataset' => $this->dataset, '--retrieval-only' => true])
        ->expectsOutputToContain('Hit@1')
        ->doesntExpectOutputToContain('Fact recall')
        ->expectsOutputToContain('2/2')
        ->assertSuccessful();

    KnowledgeBaseAssistant::assertNeverPrompted();
});

it('removes users left behind by interrupted runs', function () {
    $stale = User::factory()->create(['email' => 'run-old@'.EvalRunner::USER_EMAIL_DOMAIN]);
    Document::factory()->for($stale)->create();
    $real = User::factory()->create();

    $this->artisan('kb:eval', ['dataset' => $this->dataset, '--retrieval-only' => true])->assertSuccessful();

    $this->assertModelMissing($stale);
    $this->assertModelExists($real);
});

it('reports invalid datasets clearly', function () {
    $dir = sys_get_temp_dir().'/kb-eval-invalid-'.uniqid();
    mkdir($dir);
    file_put_contents("{$dir}/cases.json", json_encode([
        'documents' => ['missing.txt'],
        'cases' => [['question' => 'Q?', 'expect_documents' => ['missing']]],
    ]));

    $this->artisan('kb:eval', ['dataset' => $dir])
        ->expectsOutputToContain('Document not found')
        ->assertFailed();

    file_put_contents("{$dir}/cases.json", '{"documents": [], "cases": [{"question": "Q?"}]}');

    $this->artisan('kb:eval', ['dataset' => $dir])
        ->expectsOutputToContain('Invalid dataset')
        ->assertFailed();

    unlink("{$dir}/cases.json");
    rmdir($dir);
});

it('aborts cleanly when a document cannot be ingested', function () {
    $dir = sys_get_temp_dir().'/kb-eval-empty-'.uniqid();
    mkdir($dir);
    file_put_contents("{$dir}/empty.txt", '   ');
    file_put_contents("{$dir}/cases.json", json_encode([
        'documents' => ['empty.txt'],
        'cases' => [['question' => 'Q?', 'expect_documents' => ['empty']]],
    ]));

    $this->artisan('kb:eval', ['dataset' => $dir])
        ->expectsOutputToContain('Could not ingest empty.txt')
        ->assertFailed();

    expect(User::count())->toBe(0);

    array_map('unlink', glob("{$dir}/*"));
    rmdir($dir);
});

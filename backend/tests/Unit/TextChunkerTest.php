<?php

use App\Services\Documents\TextChunker;

it('returns no chunks for empty text', function () {
    expect((new TextChunker(100, 10))->split("  \n\n "))->toBe([]);
});

it('keeps short text as a single chunk', function () {
    expect((new TextChunker(100, 10))->split('Hello world.'))->toBe(['Hello world.']);
});

it('never exceeds the chunk size', function () {
    $text = implode("\n\n", array_map(
        fn ($i) => "Paragraph {$i}. ".str_repeat("Sentence number {$i} goes here. ", 12),
        range(1, 20),
    ));

    $chunks = (new TextChunker(300, 50))->split($text);

    expect($chunks)->not->toBeEmpty();

    foreach ($chunks as $chunk) {
        expect(mb_strlen($chunk))->toBeLessThanOrEqual(300);
    }
});

it('covers all of the source text', function () {
    $words = array_map(fn ($i) => "word{$i}", range(1, 500));
    $chunks = (new TextChunker(200, 40))->split(implode(' ', $words));
    $joined = implode(' ', $chunks);

    foreach ($words as $word) {
        expect($joined)->toContain($word);
    }
});

it('overlaps consecutive chunks', function () {
    $text = implode(' ', array_map(fn ($i) => "w{$i}", range(1, 300)));
    [$first, $second] = (new TextChunker(200, 50))->split($text);

    $tail = mb_substr($first, -20);

    expect($second)->toContain(trim($tail));
});

it('hard splits text without separators and handles multibyte characters', function () {
    $chunks = (new TextChunker(10, 0))->split(str_repeat('ä', 25));

    expect($chunks)->toBe([str_repeat('ä', 10), str_repeat('ä', 10), str_repeat('ä', 5)]);
});

it('rejects an overlap that is not smaller than the size', function () {
    new TextChunker(100, 100);
})->throws(InvalidArgumentException::class);

/** A section with a heading and $sentences sentences of body text. */
function section(string $heading, int $sentences): string
{
    return $heading."\n\n".str_repeat("Body text about {$heading} continues here. ", $sentences);
}

it('starts a new chunk at a section heading, without overlap from the previous section', function () {
    $text = implode("\n\n", [section('## Parking', 8), section('## Pets', 8), section('## Emergencies', 8)]);

    $chunks = (new TextChunker(600, 150))->split($text);

    expect($chunks)->toHaveCount(3)
        ->and($chunks[0])->toStartWith('## Parking')
        ->and($chunks[1])->toStartWith('## Pets')->not->toContain('Parking')
        ->and($chunks[2])->toStartWith('## Emergencies')->not->toContain('Pets');
});

it('merges small sections instead of making tiny chunks', function () {
    $text = implode("\n\n", [section('## A', 1), section('## B', 1), section('## C', 1)]);

    expect((new TextChunker(600, 150))->split($text))->toHaveCount(1);
});

it('never ends a chunk with a heading', function () {
    $text = implode("\n\n", [section('6. Daily allowances by country', 20), section('7. Taxis and local transport', 20)]);

    $chunks = (new TextChunker(400, 100))->split($text);

    foreach ($chunks as $chunk) {
        $lastLine = trim(collect(explode("\n", $chunk))->last());
        expect($lastLine)->not->toBeIn(['6. Daily allowances by country', '7. Taxis and local transport']);
    }
    expect(collect($chunks)->filter(fn ($c) => str_starts_with($c, '7. Taxis')))->toHaveCount(1);
});

it('keeps a heading with an oversized paragraph after it', function () {
    $table = str_repeat('Finland: allowance 53 euros; hotel limit 180 euros. ', 11); // one 580-character paragraph
    $text = section('Intro', 6)."\n\n6. Daily allowances by country\n\n".$table;

    $chunks = (new TextChunker(600, 100))->split($text);

    expect($chunks)->toHaveCount(2)
        ->and($chunks[1])->toStartWith("6. Daily allowances by country\n\nFinland");
});

it('recognises headings as they look after PDF and DOCX extraction', function () {
    $isHeading = fn (string $line) => (fn () => self::isHeading($line))->call(new TextChunker);

    expect($isHeading('## Pets'))->toBeTrue()
        ->and($isHeading('6. Daily allowances and hotel limits by country'))->toBeTrue()
        ->and($isHeading('Passwords and authentication'))->toBeTrue()
        ->and($isHeading('4. VACATION'))->toBeTrue()
        ->and($isHeading('1. Pull out the battery tab.'))->toBeFalse()
        ->and($isHeading('Node LED codes:'))->toBeFalse()
        ->and($isHeading('Q: How do I print?'))->toBeFalse()
        ->and($isHeading('a lowercase line'))->toBeFalse()
        ->and($isHeading(str_repeat('Long line ', 10)))->toBeFalse();
});

it('labels chunks that continue a section with its heading', function () {
    $text = str_repeat('Intro paragraph text. ', 5)."\n\n".section('## Daily allowances', 40)."\n\n".section('## Taxis', 3);

    $chunks = (new TextChunker(500, 100))->chunks($text);

    expect($chunks[0]['section'])->toBeNull(); // before any heading
    $allowances = array_values(array_filter($chunks, fn ($c) => str_contains($c['content'], 'Daily allowances continues')));
    expect($allowances[0]['section'])->toBeNull() // starts with its own heading
        ->and($allowances[0]['content'])->toStartWith('## Daily allowances');

    foreach (array_slice($allowances, 1) as $continuation) {
        expect($continuation['section'])->toBe('Daily allowances')
            ->and($continuation['content'])->not->toContain('Taxis continues');
    }
    expect(collect($chunks)->last()['content'])->toStartWith('## Taxis');
});

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

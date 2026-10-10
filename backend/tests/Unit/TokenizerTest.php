<?php

use App\Services\Retrieval\Tokenizer;

it('keeps distinctive terms and drops stopwords', function () {
    expect(Tokenizer::tokens('What is the IP54 rating of the sensors?'))
        ->toBe(['ip54', 'rating', 'sensor']);
});

it('keeps codes, hyphenated terms and numbers whole', function () {
    expect(Tokenizer::tokens('LTE-M modems cost 5,000 euros, version 2.1, Bob’s X9'))
        ->toBe(['lte-m', 'modem', 'cost', '5,000', 'euro', 'version', '2.1', 'bob', 'x9']);
});

it('folds case and simple plurals but not "ss" endings', function () {
    expect(Tokenizer::tokens('Sensors SENSOR access glass'))->toBe(['sensor', 'sensor', 'access', 'glass']);
});

it('returns nothing for a question made of stopwords', function () {
    expect(Tokenizer::tokens('What is it, and how?'))->toBe([]);
});

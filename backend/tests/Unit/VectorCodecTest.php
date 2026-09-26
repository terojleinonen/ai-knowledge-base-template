<?php

use App\Services\Retrieval\VectorCodec;

it('round trips vectors through base64 float32', function () {
    $vector = [0.5, -0.25, 1.0, 0.125];

    expect(VectorCodec::decode(VectorCodec::encode($vector)))->toBe($vector)
        ->and(strlen(base64_decode(VectorCodec::encode($vector))))->toBe(16);
});

it('normalizes to unit length', function () {
    $v = VectorCodec::normalize([3, 4]);

    expect($v)->toBe([0.6, 0.8])
        ->and(VectorCodec::dot($v, $v))->toEqualWithDelta(1.0, 1e-9);
});

it('leaves zero vectors untouched', function () {
    expect(VectorCodec::normalize([0, 0]))->toBe([0.0, 0.0]);
});

it('decodes invalid input to an empty vector', function () {
    expect(VectorCodec::decode('not base64!'))->toBe([]);
});

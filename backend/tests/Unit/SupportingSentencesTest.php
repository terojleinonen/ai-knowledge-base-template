<?php

use App\Services\Chat\SupportingSentences;

$vacation = "## 6. Vacation\n\nFull-time employees earn 2.5 vacation days for each full month of employment, which amounts to 30 days per year. The vacation year runs from 1 April to 31 March.\n\nAt least 12 vacation days must be taken as a continuous summer holiday between 2 May and 30 September. Unused vacation days can be carried over to the next vacation year, up to a maximum of 15 days.";

$allowances = "Finland (Helsinki, Tampere and other cities): daily allowance 53 euros; hotel limit 180 euros per night.\nSweden (Stockholm): daily allowance 64 euros; hotel limit 210 euros per night.\nUnited Kingdom (London): daily allowance 76 euros; hotel limit 260 euros per night.";

it('highlights the passage sentences each cited claim rests on', function () use ($vacation) {
    $answer = "You earn **30 vacation days per year** [1].\n\n- Up to 15 unused days can be carried over to the next vacation year [1].";

    expect((new SupportingSentences)->find($answer, [$vacation]))->toBe([1 => [
        'Full-time employees earn 2.5 vacation days for each full month of employment, which amounts to 30 days per year.',
        'Unused vacation days can be carried over to the next vacation year, up to a maximum of 15 days.',
    ]]);
});

it('tells similar sentences apart by their numbers and names', function () use ($allowances, $vacation) {
    $highlights = (new SupportingSentences)->find('The hotel limit in London is 260 euros per night [1].', [$allowances]);
    expect($highlights[1])->toBe(['United Kingdom (London): daily allowance 76 euros; hotel limit 260 euros per night.']);

    $highlights = (new SupportingSentences)->find('At least 12 days must be a continuous summer holiday [1].', [$vacation]);
    expect($highlights[1])->toBe(['At least 12 vacation days must be taken as a continuous summer holiday between 2 May and 30 September.']);
});

it('matches each source separately and ignores uncited sentences', function () use ($vacation, $allowances) {
    $answer = 'Unused vacation days carry over up to 15 days [1]. In Stockholm the daily allowance is 64 euros [2]. Ask your manager if unsure.';

    expect((new SupportingSentences)->find($answer, [$vacation, $allowances]))->toBe([
        1 => ['Unused vacation days can be carried over to the next vacation year, up to a maximum of 15 days.'],
        2 => ['Sweden (Stockholm): daily allowance 64 euros; hotel limit 210 euros per night.'],
    ]);
});

it('highlights nothing when no sentence clearly supports the claim', function () use ($vacation) {
    expect((new SupportingSentences)->find('Parking is free for all employees [1].', [$vacation]))->toBe([])
        ->and((new SupportingSentences)->find('No citations here.', [$vacation]))->toBe([])
        ->and((new SupportingSentences)->find('A claim [3].', [$vacation]))->toBe([]); // unknown source
});

it('returns exact substrings of the passage', function () use ($vacation, $allowances) {
    $highlights = (new SupportingSentences)->find('30 days per year [1]; London hotels up to 260 euros [2].', [$vacation, $allowances]);

    foreach ([1 => $vacation, 2 => $allowances] as $n => $passage) {
        foreach ($highlights[$n] as $sentence) {
            expect(str_contains($passage, $sentence))->toBeTrue();
        }
    }
});

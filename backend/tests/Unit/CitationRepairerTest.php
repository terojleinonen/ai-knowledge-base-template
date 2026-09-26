<?php

use App\Services\Chat\CitationRepairer;

// Source passages modelled on the real test corpus.
$handbookVacation = 'Vacation. Full-time employees earn 2.5 vacation days for each full month of employment, which amounts to 30 days per year. At least 12 days must be taken as a continuous summer holiday. Unused vacation days can be carried over to the next year, up to a maximum of 10 days.';
$handbookRemote = 'Remote work. Employees may work remotely up to four days per week. Working abroad is allowed for a maximum of 30 days per calendar year.';
$security = 'Passwords and authentication. The approved password manager is 1Password. Passwords must be at least 16 characters long. Multi-factor authentication is mandatory.';
$aurora = 'Aurora Node is powered by two AA lithium batteries with an expected battery life of 5 years. All hardware comes with a 3-year limited warranty. Batteries are not covered by the warranty.';

beforeEach(fn () => $this->repairer = new CitationRepairer);

it('moves a wrong citation to the source that supports the sentence', function () use ($handbookVacation, $handbookRemote, $security) {
    // Real qwen2.5:3b output: facts from [1], cited as [3].
    $answer = 'Full-time employees earn 2.5 vacation days for each full month of employment, which amounts to 30 days per year. Unused vacation days can be carried over to the next year, up to a maximum of 10 days. [3]';

    expect($this->repairer->repair($answer, [$handbookVacation, $handbookRemote, $security]))->toBe(
        'Full-time employees earn 2.5 vacation days for each full month of employment, which amounts to 30 days per year [1]. Unused vacation days can be carried over to the next year, up to a maximum of 10 days [1].'
    );
});

it('keeps correct citations untouched', function () use ($handbookVacation, $security) {
    $answer = 'Employees earn 30 vacation days per year [1]. The approved password manager is 1Password [2].';

    expect($this->repairer->repair($answer, [$handbookVacation, $security]))->toBe($answer);
});

it('adds citations to uncited factual sentences', function () use ($handbookVacation, $aurora) {
    // Real qwen2.5:3b output with no citations at all.
    $answer = 'The Aurora Node battery lasts for 5 years at the default reporting interval. The warranty for hardware is a 3-year limited warranty, with batteries not covered by this warranty.';

    expect($this->repairer->repair($answer, [$aurora, $handbookVacation]))->toBe(
        'The Aurora Node battery lasts for 5 years at the default reporting interval [1]. The warranty for hardware is a 3-year limited warranty, with batteries not covered by this warranty [1].'
    );
});

it('rewrites citations used as the sentence subject', function () use ($security, $handbookVacation) {
    // Real qwen2.5:3b output.
    $answer = '[1] states that we must use 1Password as the password manager and passwords must be at least 16 characters long.';

    expect($this->repairer->repair($answer, [$security, $handbookVacation]))->toBe(
        'The source states that we must use 1Password as the password manager and passwords must be at least 16 characters long [1].'
    );
});

it('removes citations from "not in the sources" sentences', function () use ($handbookVacation, $security) {
    expect($this->repairer->repair('The information about parking is not provided in the given sources. [1]', [$handbookVacation, $security]))
        ->toBe('The information about parking is not provided in the given sources.')
        ->and($this->repairer->repair('[1] and [2] do not contain any information regarding parking.', [$handbookVacation, $security]))
        ->toBe('The sources do not contain any information regarding parking.');
});

it('keeps multiple citations when a sentence combines facts from several sources', function () use ($handbookVacation, $security) {
    $answer = 'Employees earn 30 vacation days per year and must store passwords in 1Password [1][2].';

    expect($this->repairer->repair($answer, [$handbookVacation, $security]))->toBe($answer);
});

it('drops citations that point to non-existent sources', function () use ($handbookVacation) {
    expect($this->repairer->repair('Employees earn 30 vacation days per year [7].', [$handbookVacation]))
        ->toBe('Employees earn 30 vacation days per year [1].');
});

it('does not cite filler sentences', function () use ($handbookVacation) {
    expect($this->repairer->repair("Here is what I found:\n\n- Employees earn 30 vacation days per year.", [$handbookVacation]))
        ->toBe("Here is what I found:\n\n- Employees earn 30 vacation days per year [1].");
});

it('preserves list structure and decimals', function () use ($handbookVacation, $handbookRemote) {
    $answer = "Key points:\n1. Employees earn 2.5 days per month [2].\n2. Remote work is allowed up to four days per week [2].";

    expect($this->repairer->repair($answer, [$handbookVacation, $handbookRemote]))->toBe(
        "Key points:\n1. Employees earn 2.5 days per month [1].\n2. Remote work is allowed up to four days per week [2]."
    );
});

it('is idempotent', function () use ($handbookVacation, $handbookRemote, $security) {
    $answer = 'Full-time employees earn 30 days per year. [3] [1] states that remote work is limited to four days per week.';
    $once = $this->repairer->repair($answer, [$handbookVacation, $handbookRemote, $security]);

    expect($this->repairer->repair($once, [$handbookVacation, $handbookRemote, $security]))->toBe($once);
});

it('returns the answer unchanged when there are no sources', function () {
    expect($this->repairer->repair('Nothing to see [1].', []))->toBe('Nothing to see [1].');
});

it('does not mistake factual negations for "not in the sources"', function () {
    $policy = 'Data classification. Customer data is always Confidential. Confidential information may never be stored on personal devices or shared through personal email.';
    $other = 'Remote work. Employees may work remotely up to four days per week.';

    expect($this->repairer->repair('Confidential information may never be shared through personal email [2].', [$policy, $other]))
        ->toBe('Confidential information may never be shared through personal email [1].');
});

it('works for non-English answers', function () {
    $loma = 'Kokoaikaiset työntekijät ansaitsevat 2,5 lomapäivää jokaiselta täydeltä työkuukaudelta, eli 30 päivää vuodessa.';
    $etatyo = 'Etätyötä saa tehdä enintään neljä päivää viikossa.';

    expect($this->repairer->repair('Työntekijät ansaitsevat 30 päivää lomaa vuodessa.', [$etatyo, $loma]))
        ->toBe('Työntekijät ansaitsevat 30 päivää lomaa vuodessa [2].');
});

it('leaves a weakly supported sentence uncited rather than guessing', function () use ($handbookVacation, $security) {
    expect($this->repairer->repair('Overall, the company seems to value a healthy work-life balance.', [$handbookVacation, $security]))
        ->toBe('Overall, the company seems to value a healthy work-life balance.');
});

it('leaves citations on very short sentences alone', function () use ($handbookVacation, $security) {
    expect($this->repairer->repair('See policy [2]. Employees get', [$handbookVacation, $security]))
        ->toBe('See policy [2]. Employees get')
        ->and($this->repairer->repair('See policy [9].', [$handbookVacation]))->toBe('See policy.');
});

it('drops a leading citation label that is not the sentence subject', function () use ($security, $handbookVacation) {
    // Real qwen2.5:3b output.
    $answer = '[1] Northwind Labs requires the use of 1Password as the password manager, and passwords must be at least 16 characters long.';

    expect($this->repairer->repair($answer, [$security, $handbookVacation]))->toBe(
        'Northwind Labs requires the use of 1Password as the password manager, and passwords must be at least 16 characters long [1].'
    );
});

it('removes inline reference phrases', function () {
    $equipment = 'Equipment. Every employee receives a laptop of their choice. Lost or stolen equipment must be reported to IT within 24 hours.';
    $devices = 'Devices. Laptops must have full-disk encryption enabled (FileVault on macOS, LUKS on Linux).';

    // Real qwen2.5:3b output (abridged).
    $answer = 'According to [2], every employee receives a laptop of their choice. You should ensure your laptop has full-disk encryption enabled, as specified in [1], to enhance security.';

    expect($this->repairer->repair($answer, [$devices, $equipment]))->toBe(
        'Every employee receives a laptop of their choice [2]. You should ensure your laptop has full-disk encryption enabled to enhance security [1].'
    );
});

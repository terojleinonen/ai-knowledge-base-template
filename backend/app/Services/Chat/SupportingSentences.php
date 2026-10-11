<?php

namespace App\Services\Chat;

use App\Services\Retrieval\Tokenizer;

/**
 * Finds the sentences of each cited passage that support the answer, so the citation
 * panel can highlight them instead of the whole passage.
 *
 * Each answer sentence with [n] markers is matched against the sentences (and list or
 * table lines) of passage n by shared distinctive words, weighted by how rare each word
 * is across all passages; numbers count double, since they usually carry the fact.
 */
class SupportingSentences
{
    /** A passage sentence must contain at least this share of the answer sentence's weight... */
    private const MIN_SUPPORT = 0.35;

    /** ...and this share of the best match's, to be highlighted alongside it. */
    private const RELATIVE_SUPPORT = 0.7;

    /** At most this many passage sentences per answer sentence. */
    private const MAX_PER_CLAIM = 2;

    private const MARKER = '/\[(\d+(?:\s*,\s*\d+)*)\]/';

    /**
     * @param  list<string>  $sources  passage texts; source [n] is $sources[n - 1]
     * @return array<int, list<string>> per source number, the supporting sentences in passage order
     *                                  (exact substrings of the passage)
     */
    public function find(string $answer, array $sources): array
    {
        $units = array_map(self::units(...), $sources);
        $idf = self::idf(array_merge([], ...$units));
        $found = [];

        foreach (self::claims($answer) as [$text, $numbers]) {
            $weights = self::weights(Tokenizer::tokens($text), $idf);
            $total = array_sum($weights);

            if ($total <= 0) {
                continue;
            }

            foreach ($numbers as $n) {
                $scores = [];

                foreach ($units[$n - 1] ?? [] as $i => $unit) {
                    $terms = array_flip(Tokenizer::tokens($unit));
                    $scores[$i] = array_sum(array_intersect_key($weights, $terms)) / $total;
                }

                arsort($scores);
                $best = reset($scores) ?: 0.0;

                if ($best < self::MIN_SUPPORT) {
                    continue;
                }

                $kept = array_filter($scores, fn (float $s) => $s >= $best * self::RELATIVE_SUPPORT);

                foreach (array_slice(array_keys($kept), 0, self::MAX_PER_CLAIM) as $i) {
                    $found[$n][$i] = true;
                }
            }
        }

        $highlights = [];

        foreach ($found as $n => $indexes) {
            ksort($indexes);
            $highlights[$n] = array_map(fn (int $i) => $units[$n - 1][$i], array_keys($indexes));
        }

        ksort($highlights);

        return $highlights;
    }

    /**
     * Answer sentences that carry citations, without their markers, with the cited numbers.
     *
     * @return list<array{0: string, 1: list<int>}>
     */
    private static function claims(string $answer): array
    {
        $claims = [];

        foreach (preg_split('/\n+|(?<=[.!?])\s+(?=\S)/u', $answer) ?: [] as $sentence) {
            preg_match_all(self::MARKER, $sentence, $matches);
            $numbers = [];

            foreach ($matches[1] as $group) {
                foreach (explode(',', $group) as $n) {
                    $numbers[(int) trim($n)] = true;
                }
            }

            if ($numbers !== []) {
                $claims[] = [(string) preg_replace(self::MARKER, '', $sentence), array_keys($numbers)];
            }
        }

        return $claims;
    }

    /**
     * A passage's sentences and lines, trimmed (each an exact substring of the passage).
     *
     * @return list<string>
     */
    private static function units(string $passage): array
    {
        $units = [];

        foreach (preg_split('/\n+/u', $passage) ?: [] as $line) {
            foreach (preg_split('/(?<=[.!?])\s+(?=\S)/u', $line) ?: [] as $sentence) {
                if (trim($sentence) !== '') {
                    $units[] = trim($sentence);
                }
            }
        }

        return $units;
    }

    /**
     * @param  list<string>  $units
     * @return array<string, float>
     */
    private static function idf(array $units): array
    {
        $frequency = [];

        foreach ($units as $unit) {
            foreach (array_unique(Tokenizer::tokens($unit)) as $term) {
                $frequency[$term] = ($frequency[$term] ?? 0) + 1;
            }
        }

        $count = max(1, count($units));

        return array_map(fn (int $df) => log(1 + $count / $df), $frequency);
    }

    /**
     * @param  list<string>  $terms
     * @param  array<string, float>  $idf
     * @return array<string, float>
     */
    private static function weights(array $terms, array $idf): array
    {
        // A term found in no passage is as distinctive as it gets: it counts fully against support.
        $unseen = $idf === [] ? 1.0 : max($idf);
        $weights = [];

        foreach (array_unique($terms) as $term) {
            $weights[$term] = ($idf[$term] ?? $unseen) * (is_numeric(str_replace(',', '.', $term)) ? 2 : 1);
        }

        return $weights;
    }
}

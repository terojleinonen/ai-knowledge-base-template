<?php

namespace App\Services\Chat;

/**
 * Checks each sentence's [n] citations against the source passages and fixes them.
 *
 * Small models often state the right facts but cite the wrong source number,
 * or none at all. Support is measured lexically: the share of a sentence's
 * content words found in a source, weighted by how distinctive each word is
 * across the sources (numbers count double, since they usually carry the fact).
 */
class CitationRepairer
{
    /** Sentences with fewer content words than this are too short to judge. */
    private const MIN_TERMS = 3;

    /** Minimum support for adding a citation to an uncited sentence. */
    private const ADD_THRESHOLD = 0.35;

    /** A kept citation needs at least this support... */
    private const KEEP_THRESHOLD = 0.2;

    /** ...and either half of the best source's support, or this much evidence the best source lacks. */
    private const UNIQUE_THRESHOLD = 0.15;

    private const MARKER = '/\[(\d+(?:\s*,\s*\d+)*)\]/';

    /** "Not in the sources" statements: a negation near a reference to the sources themselves. */
    private const NOT_FOUND = '/\b(?:not|no|never|cannot|n\'t)\b.{0,60}\b(?:sources?|documents?|context|provided (?:text|information))\b|\b(?:sources?|documents?|context)\b.{0,40}\b(?:not|no|n\'t)\b|\bno (?:information|mention|details)\b|\b(?:could|can)(?:\'t| ?not) find\b/i';

    private const STOPWORDS = [
        'the', 'and', 'for', 'are', 'was', 'were', 'with', 'that', 'this', 'these', 'those', 'from', 'into', 'your',
        'you', 'our', 'their', 'they', 'them', 'its', 'has', 'have', 'had', 'but', 'not', 'all', 'any', 'can', 'may',
        'must', 'will', 'would', 'should', 'could', 'which', 'what', 'when', 'where', 'who', 'how', 'also', 'each',
        'per', 'than', 'then', 'there', 'here', 'about', 'such', 'other', 'only', 'more', 'most', 'some', 'been',
        'being', 'does', 'did', 'doe', 'one', 'use', 'used', 'source', 'sources', 'according', 'state', 'states',
        'stated', 'mention', 'mentions', 'based', 'provided', 'information',
    ];

    /** @var array<string, float> */
    private array $idf = [];

    /** @var list<array<string, true>> */
    private array $sourceTerms = [];

    /**
     * @param  list<string>  $sources  source passages; source [n] is $sources[n - 1]
     */
    public function repair(string $answer, array $sources): string
    {
        if ($sources === [] || trim($answer) === '') {
            return $answer;
        }

        $this->index($sources);

        $lines = array_map(
            fn (string $line) => implode(' ', array_map($this->repairSentence(...), $this->sentences($line))),
            explode("\n", $answer),
        );

        return implode("\n", $lines);
    }

    private function repairSentence(string $sentence): string
    {
        // Keep list markers ("- ", "1. ") out of the analysis.
        preg_match('/^(\s*(?:[-*•]|\d+[.)])\s+)?(.*)$/su', $sentence, $parts);
        $prefix = $parts[1];
        $body = $parts[2];

        $cited = $this->citedNumbers($body);
        $text = $this->stripMarkers($body);

        if (preg_match('/[\p{L}\p{N}]/u', $text) !== 1) {
            return $prefix.$text;
        }

        if (preg_match(self::NOT_FOUND, $text) === 1) {
            return $prefix.$text;
        }

        $terms = $this->terms($text);

        // Too little text to judge either way: keep valid citations, add none.
        if (count($terms) < self::MIN_TERMS) {
            return $prefix.$this->withMarkers($text, array_values(array_filter(
                $cited,
                fn (int $n) => isset($this->sourceTerms[$n - 1]),
            )));
        }

        $weights = $this->weights($terms);
        $total = array_sum($weights);
        $scores = array_map(fn (array $source) => $this->support($weights, $source) / $total, $this->sourceTerms);
        $best = max($scores);
        $bestIndex = (int) array_search($best, $scores, true);
        $bestSource = $this->sourceTerms[$bestIndex];

        $keep = array_values(array_filter($cited, function (int $n) use ($scores, $best, $weights, $total, $bestSource) {
            $source = $this->sourceTerms[$n - 1] ?? null;

            if ($source === null || $scores[$n - 1] < self::KEEP_THRESHOLD) {
                return false;
            }

            $unique = $this->support(array_diff_key($weights, $bestSource), $source) / $total;

            return $scores[$n - 1] >= $best / 2 || $unique >= self::UNIQUE_THRESHOLD;
        }));

        if ($keep === [] && $best >= self::ADD_THRESHOLD) {
            $keep = [$bestIndex + 1];
        }

        return $prefix.$this->withMarkers($text, $keep);
    }

    /**
     * Split a line into sentences without breaking decimals or list numbering.
     *
     * @return list<string>
     */
    private function sentences(string $line): array
    {
        $fragments = preg_split('/(?<=[.!?])\s+(?=\S)/u', $line) ?: [$line];
        $sentences = [];

        foreach ($fragments as $fragment) {
            $last = array_key_last($sentences);

            // A fragment that is only citations belongs to the previous sentence ("... days. [3]").
            if ($last !== null && preg_match('/^(\s*\[\d+(?:\s*,\s*\d+)*\]\s*)+[.!?]?$/', $fragment) === 1) {
                $sentences[$last] .= ' '.$fragment;
            } elseif ($last !== null && preg_match('/^\s*(?:\d+[.)]|[-*•])$/', $sentences[$last]) === 1) {
                // A bare list number ("1.") belongs to the sentence that follows it.
                $sentences[$last] .= ' '.$fragment;
            } else {
                $sentences[] = $fragment;
            }
        }

        return $sentences;
    }

    /**
     * @return list<int>
     */
    private function citedNumbers(string $text): array
    {
        preg_match_all(self::MARKER, $text, $matches);

        $numbers = [];
        foreach ($matches[1] as $group) {
            foreach (explode(',', $group) as $n) {
                $numbers[] = (int) trim($n);
            }
        }

        return array_values(array_unique($numbers));
    }

    private function stripMarkers(string $text): string
    {
        $group = '(?:\[\d+(?:\s*,\s*\d+)*\]\s*(?:,|and|&)?\s*)+';

        // Reference phrases: "According to [2], ..." / "..., as specified in [1], ..." -> removed entirely.
        $text = preg_replace(
            '/,?\s*\b(?:according to|as (?:stated|specified|mentioned|noted|described|shown|outlined|indicated|listed) in|(?:see|per))\s+(?:sources?\s+)?'.$group.',?/iu',
            ' ',
            $text,
        ) ?? $text;

        // Citations used as the grammatical subject: "[1] states..." -> "The source states...".
        $text = preg_replace_callback(
            '/^\s*('.$group.')(?=(?:states?|says?|mentions?|notes?|indicates?|shows?|explains?|specif(?:y|ies)|confirms?|describes?|lists?|contains?|do|does|did|is|are|has|have)\b)/iu',
            fn (array $m) => count($this->citedNumbers($m[1])) > 1 ? 'The sources ' : 'The source ',
            $text,
        ) ?? $text;

        $text = preg_replace('/\s*'.trim(self::MARKER, '/').'/', '', $text) ?? $text;
        $text = preg_replace('/\s+([.,;:!?])/', '$1', $text) ?? $text;
        $text = trim(preg_replace('/\s{2,}/', ' ', $text) ?? $text);

        return mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }

    /**
     * @param  list<int>  $numbers
     */
    private function withMarkers(string $text, array $numbers): string
    {
        if ($numbers === []) {
            return $text;
        }

        sort($numbers);
        $markers = implode('', array_map(fn (int $n) => "[{$n}]", $numbers));

        return preg_match('/^(.*?)([.!?:;]+["\')]*)$/su', $text, $m) === 1
            ? "{$m[1]} {$markers}{$m[2]}"
            : "{$text} {$markers}";
    }

    /**
     * @param  list<string>  $sources
     */
    private function index(array $sources): void
    {
        $this->sourceTerms = array_map(fn (string $s) => array_fill_keys($this->terms($s), true), $sources);

        $documentFrequency = [];
        foreach ($this->sourceTerms as $terms) {
            foreach (array_keys($terms) as $term) {
                $documentFrequency[$term] = ($documentFrequency[$term] ?? 0) + 1;
            }
        }

        $count = count($sources);
        $this->idf = array_map(fn (int $df) => log(1 + $count / $df), $documentFrequency);
    }

    /**
     * Weight of each term: rarer across sources = more distinctive. Numbers count double.
     *
     * @param  list<string>  $terms
     * @return array<string, float>
     */
    private function weights(array $terms): array
    {
        $unseen = log(1 + max(1, count($this->sourceTerms)));
        $weights = [];

        foreach ($terms as $term) {
            $weights[$term] = ($this->idf[$term] ?? $unseen) * (is_numeric(str_replace(',', '.', $term)) ? 2 : 1);
        }

        return $weights;
    }

    /**
     * Total weight of the given terms that appear in the source.
     *
     * @param  array<string, float>  $weights
     * @param  array<string, true>  $source
     */
    private function support(array $weights, array $source): float
    {
        return array_sum(array_intersect_key($weights, $source));
    }

    /**
     * Content words and numbers, lowercased, with a light plural stemmer.
     *
     * @return list<string>
     */
    private function terms(string $text): array
    {
        // Alphanumeric words stay whole: "1password", "2.5", "3-year", "full-time".
        preg_match_all('/[\p{L}\p{N}]+(?:[.,\'-][\p{L}\p{N}]+)*/u', mb_strtolower($text), $matches);

        $terms = [];
        foreach ($matches[0] as $word) {
            if (! is_numeric(str_replace(',', '.', $word))) {
                if (mb_strlen($word) < 3 || in_array($word, self::STOPWORDS, true)) {
                    continue;
                }

                if (mb_strlen($word) > 4 && str_ends_with($word, 's') && ! str_ends_with($word, 'ss')) {
                    $word = mb_substr($word, 0, -1);
                }
            }

            $terms[$word] = true;
        }

        return array_keys($terms);
    }
}

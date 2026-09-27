<?php

namespace App\Evaluation;

final class Summary
{
    /**
     * Aggregate metrics. Ratios are null when there was nothing to measure.
     *
     * @param  list<CaseResult>  $results
     * @return array<string, int|float|null>
     */
    public static function of(array $results): array
    {
        $answerable = array_values(array_filter($results, fn (CaseResult $r) => ! $r->case->unanswerable));
        $unanswerable = array_values(array_filter($results, fn (CaseResult $r) => $r->case->unanswerable));
        $answered = array_values(array_filter($results, fn (CaseResult $r) => $r->answer !== null));
        $answeredAnswerable = array_values(array_filter($answerable, fn (CaseResult $r) => $r->answer !== null));
        $answeredUnanswerable = array_values(array_filter($unanswerable, fn (CaseResult $r) => $r->answer !== null));
        $judged = array_values(array_filter($results, fn (CaseResult $r) => $r->passed() !== null));

        $citations = array_sum(array_map(fn (CaseResult $r) => $r->citations, $answeredAnswerable));

        return [
            'cases' => count($results),
            'passed' => count(array_filter($judged, fn (CaseResult $r) => $r->passed())),
            'judged' => count($judged),

            // Retrieval
            'hit_at_1' => self::ratio(count(array_filter($answerable, fn (CaseResult $r) => $r->rank === 1)), count($answerable)),
            'hit_at_k' => self::ratio(count(array_filter($answerable, fn (CaseResult $r) => $r->rank !== null)), count($answerable)),
            'mrr' => $answerable === [] ? null : array_sum(array_map(fn (CaseResult $r) => $r->rank ? 1 / $r->rank : 0, $answerable)) / count($answerable),
            'off_topic_filtered' => self::ratio(count(array_filter($unanswerable, fn (CaseResult $r) => $r->retrievedDocuments === [])), count($unanswerable)),

            // Answers
            'fact_recall' => self::ratio(
                array_sum(array_map(fn (CaseResult $r) => $r->factsFound, $answeredAnswerable)),
                array_sum(array_map(fn (CaseResult $r) => $r->factsTotal(), $answeredAnswerable)),
            ),
            'correct_abstentions' => self::ratio(count(array_filter($answeredUnanswerable, fn (CaseResult $r) => $r->abstained)), count($answeredUnanswerable)),
            'false_abstentions' => $answeredAnswerable === [] ? null : count(array_filter($answeredAnswerable, fn (CaseResult $r) => $r->abstained)),
            'citation_precision' => self::ratio(array_sum(array_map(fn (CaseResult $r) => $r->correctCitations, $answeredAnswerable)), $citations),
            'uncited_answers' => $answeredAnswerable === [] ? null : count(array_filter($answeredAnswerable, fn (CaseResult $r) => $r->citations === 0 && ! $r->abstained)),

            // Latency
            'retrieval_ms_p50' => self::median(array_map(fn (CaseResult $r) => $r->retrievalMs, $results)),
            'answer_ms_p50' => self::median(array_map(fn (CaseResult $r) => (float) $r->answerMs, $answered)),
            'answer_ms_max' => $answered === [] ? null : max(array_map(fn (CaseResult $r) => (float) $r->answerMs, $answered)),
        ];
    }

    private static function ratio(int|float $numerator, int|float $denominator): ?float
    {
        return $denominator > 0 ? $numerator / $denominator : null;
    }

    /**
     * @param  list<float>  $values
     */
    private static function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }
}

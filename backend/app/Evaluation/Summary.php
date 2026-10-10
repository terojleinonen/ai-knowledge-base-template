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

        $withEvidence = array_values(array_filter($answerable, fn (CaseResult $r) => $r->evidenceTotal() > 0));
        $answeredWithEvidence = array_values(array_filter($withEvidence, fn (CaseResult $r) => $r->answer !== null));
        $citations = array_sum(array_map(fn (CaseResult $r) => $r->citations, $answeredAnswerable));

        return [
            'cases' => count($results),
            'passed' => count(array_filter($judged, fn (CaseResult $r) => $r->passed())),
            'judged' => count($judged),

            // Retrieval
            'hit_at_1' => self::ratio(count(array_filter($answerable, fn (CaseResult $r) => $r->rank === 1)), count($answerable)),
            'hit_at_k' => self::ratio(count(array_filter($answerable, fn (CaseResult $r) => $r->rank !== null)), count($answerable)),
            'mrr' => $answerable === [] ? null : array_sum(array_map(fn (CaseResult $r) => $r->rank ? 1 / $r->rank : 0, $answerable)) / count($answerable),
            'evidence_recall' => self::ratio(
                array_sum(array_map(fn (CaseResult $r) => $r->evidenceFound, $answerable)),
                array_sum(array_map(fn (CaseResult $r) => $r->evidenceTotal(), $answerable)),
            ),
            'evidence_hit_at_1' => self::ratio(
                count(array_filter($withEvidence, fn (CaseResult $r) => $r->evidenceRank === 1)),
                count($withEvidence),
            ),
            'off_topic_filtered' => self::ratio(count(array_filter($unanswerable, fn (CaseResult $r) => $r->retrievedDocuments === [])), count($unanswerable)),

            // Answers
            'fact_recall' => self::ratio(
                array_sum(array_map(fn (CaseResult $r) => $r->factsFound, $answeredAnswerable)),
                array_sum(array_map(fn (CaseResult $r) => $r->factsTotal(), $answeredAnswerable)),
            ),
            'correct_abstentions' => self::ratio(count(array_filter($answeredUnanswerable, fn (CaseResult $r) => $r->abstained)), count($answeredUnanswerable)),
            'false_abstentions' => $answeredAnswerable === [] ? null : count(array_filter($answeredAnswerable, fn (CaseResult $r) => $r->abstained)),
            'citation_precision' => self::ratio(array_sum(array_map(fn (CaseResult $r) => $r->correctCitations, $answeredAnswerable)), $citations),
            'passage_citation_precision' => self::ratio(
                array_sum(array_map(fn (CaseResult $r) => $r->evidenceCitations, $answeredWithEvidence)),
                array_sum(array_map(fn (CaseResult $r) => $r->citations, $answeredWithEvidence)),
            ),
            'uncited_answers' => $answeredAnswerable === [] ? null : count(array_filter($answeredAnswerable, fn (CaseResult $r) => $r->citations === 0 && ! $r->abstained)),

            // Chat model tokens (answered cases)
            'input_tokens' => array_sum(array_map(fn (CaseResult $r) => $r->inputTokens, $answered)),
            'output_tokens' => array_sum(array_map(fn (CaseResult $r) => $r->outputTokens, $answered)),
            'model_calls' => array_sum(array_map(fn (CaseResult $r) => $r->modelCalls, $answered)),

            // Latency
            'retrieval_ms_p50' => self::median(array_map(fn (CaseResult $r) => $r->retrievalMs, $results)),
            'answer_ms_p50' => self::median(array_map(fn (CaseResult $r) => (float) $r->answerMs, $answered)),
            'answer_ms_max' => $answered === [] ? null : max(array_map(fn (CaseResult $r) => (float) $r->answerMs, $answered)),
        ];
    }

    /**
     * Pass rate and retrieval quality per tag (question type).
     *
     * @param  list<CaseResult>  $results
     * @return array<string, array{cases: int, passed: int, judged: int, evidence_recall: float|null}>
     */
    public static function byTag(array $results): array
    {
        $tags = [];

        foreach ($results as $r) {
            foreach ($r->case->tags as $tag) {
                $tags[$tag][] = $r;
            }
        }

        ksort($tags);

        return array_map(function (array $group) {
            $judged = array_filter($group, fn (CaseResult $r) => $r->passed() !== null);

            return [
                'cases' => count($group),
                'passed' => count(array_filter($judged, fn (CaseResult $r) => $r->passed())),
                'judged' => count($judged),
                'evidence_recall' => self::ratio(
                    array_sum(array_map(fn (CaseResult $r) => $r->evidenceFound, $group)),
                    array_sum(array_map(fn (CaseResult $r) => $r->evidenceTotal(), $group)),
                ),
            ];
        }, $tags);
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

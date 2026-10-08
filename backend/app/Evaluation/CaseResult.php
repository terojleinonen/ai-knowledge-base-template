<?php

namespace App\Evaluation;

final class CaseResult
{
    /** 1-based rank of the first chunk from an expected document, or null if none was retrieved. */
    public ?int $rank = null;

    /** @var list<string> */
    public array $retrievedDocuments = [];

    public float $topScore = 0.0;

    public float $retrievalMs = 0.0;

    public ?string $answer = null;

    public ?float $answerMs = null;

    public int $factsFound = 0;

    /** Chat model calls and tokens for this case's answer (output includes thinking). */
    public int $modelCalls = 0;

    public int $inputTokens = 0;

    public int $outputTokens = 0;

    public bool $abstained = false;

    public int $citations = 0;

    public int $correctCitations = 0;

    /** @var list<string> */
    public array $missingFacts = [];

    public function __construct(public readonly EvalCase $case) {}

    public function factsTotal(): int
    {
        return count($this->case->facts);
    }

    /**
     * Whether this case met every expectation that was measured, or null if nothing
     * decisive was measured (an unanswerable question in retrieval-only mode: only
     * the model can say "not covered" for on-topic questions the documents don't answer).
     */
    public function passed(): ?bool
    {
        if ($this->case->unanswerable) {
            return $this->answer === null ? null : $this->abstained;
        }

        if ($this->rank === null) {
            return false;
        }

        if ($this->answer === null) {
            return true;
        }

        return $this->factsFound === $this->factsTotal()
            && ! $this->abstained
            && $this->citations > 0
            && $this->correctCitations === $this->citations;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'question' => $this->case->question,
            'unanswerable' => $this->case->unanswerable,
            'expect_documents' => $this->case->expectDocuments,
            'passed' => $this->passed(),
            'rank' => $this->rank,
            'top_score' => round($this->topScore, 4),
            'retrieved_documents' => $this->retrievedDocuments,
            'retrieval_ms' => round($this->retrievalMs),
            'answer' => $this->answer,
            'answer_ms' => $this->answerMs === null ? null : round($this->answerMs),
            'model_calls' => $this->modelCalls,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'facts_found' => $this->factsFound,
            'facts_total' => $this->factsTotal(),
            'missing_facts' => $this->missingFacts,
            'abstained' => $this->abstained,
            'citations' => $this->citations,
            'correct_citations' => $this->correctCitations,
        ];
    }
}

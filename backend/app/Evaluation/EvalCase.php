<?php

namespace App\Evaluation;

final readonly class EvalCase
{
    /**
     * @param  list<string>  $expectDocuments  titles (file names without extension) that should answer the question
     * @param  list<list<string>>  $facts  each group must appear in the answer; alternatives within a group are OR-ed
     * @param  list<string>  $after  earlier questions in the same conversation (the question is a follow-up)
     */
    public function __construct(
        public string $question,
        public array $expectDocuments = [],
        public array $facts = [],
        public bool $unanswerable = false,
        public array $after = [],
    ) {}
}

<?php

namespace App\Evaluation;

use App\Enums\MessageRole;
use App\Models\Chunk;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Chat\AnswerQuestion;
use App\Services\Chat\CitationRepairer;
use App\Services\Documents\DocumentIngestor;
use App\Services\Retrieval\Retriever;
use App\Services\Retrieval\SearchResult;
use Closure;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Ai\Events\AgentPrompted;

/**
 * Runs a dataset against the real ingestion, retrieval and answering pipeline
 * using a throwaway user, which is deleted (with its documents and files) afterwards.
 */
class EvalRunner
{
    public const USER_EMAIL_DOMAIN = 'kb-eval.invalid';

    /** The case currently being evaluated, for attributing model usage. */
    private ?CaseResult $current = null;

    public function __construct(
        private readonly Retriever $retriever,
        private readonly AnswerQuestion $answerQuestion,
        private readonly DocumentIngestor $ingestor,
    ) {}

    /**
     * @param  (Closure(CaseResult, int): void)|null  $onResult  called after each case with its 1-based number
     * @return list<CaseResult>
     */
    public function run(Dataset $dataset, bool $withAnswers = true, ?int $limit = null, ?Closure $onResult = null): array
    {
        self::cleanUp();

        // Attribute the chat model's calls and token usage to the case being evaluated.
        Event::listen(AgentPrompted::class, function (AgentPrompted $event) {
            if ($this->current !== null) {
                $this->current->modelCalls++;
                $this->current->inputTokens += $event->response->usage->inputTokens;
                $this->current->outputTokens += $event->response->usage->outputTokens;
            }
        });

        $user = User::create([
            'name' => 'KB Eval',
            'email' => 'run-'.Str::lower((string) Str::ulid()).'@'.self::USER_EMAIL_DOMAIN,
            'password' => Str::random(40),
        ]);

        try {
            $this->ingest($user, $dataset->documents);

            $passages = Chunk::where('user_id', $user->id)->pluck('content')->map(self::normalize(...))->all();
            $results = [];

            foreach (array_slice($dataset->cases, 0, $limit) as $i => $case) {
                $this->current = new CaseResult($case);
                $this->current->unknownEvidence = array_values(array_filter(array_merge(...$case->evidence),
                    fn (string $e) => ! array_filter($passages, fn (string $p) => str_contains($p, self::normalize($e)))));
                $results[] = $result = $this->evaluate($user, $this->current, $withAnswers);
                $onResult?->__invoke($result, $i + 1);
            }

            return $results;
        } finally {
            $this->current = null;
            self::deleteUser($user);
        }
    }

    /**
     * Remove users left behind by interrupted runs.
     */
    public static function cleanUp(): void
    {
        User::where('email', 'like', '%@'.self::USER_EMAIL_DOMAIN)->get()->each(self::deleteUser(...));
    }

    private static function deleteUser(User $user): void
    {
        // Delete documents one by one so their stored files are removed too.
        $user->documents()->get()->each->delete();
        $user->delete();
    }

    /**
     * @param  list<string>  $paths
     */
    private function ingest(User $user, array $paths): void
    {
        foreach ($paths as $path) {
            $this->ingestor->ingest($user, $path);
        }
    }

    private function evaluate(User $user, CaseResult $result, bool $withAnswers): CaseResult
    {
        $case = $result->case;
        $conversation = $this->conversation($user, $case->after, $withAnswers);

        $start = hrtime(true);
        $result->searchQuery = $this->answerQuestion->searchQuery($case->question, $conversation);
        $hits = $this->retriever->retrieve($user->id, $result->searchQuery);
        $result->retrievalMs = (hrtime(true) - $start) / 1e6;

        $result->retrievedDocuments = array_map(fn (SearchResult $hit) => $hit->documentTitle, $hits);
        $result->topScore = $hits[0]->score ?? 0.0;

        foreach ($result->retrievedDocuments as $i => $title) {
            if (in_array($title, $case->expectDocuments, true)) {
                $result->rank = $i + 1;
                break;
            }
        }

        $retrieved = array_map(fn (SearchResult $hit) => $hit->content, $hits);
        $result->evidenceFound = count(array_filter($case->evidence, fn (array $group) => $this->containsEvidence($retrieved, $group)));

        foreach ($retrieved as $i => $content) {
            if ($this->hasEvidence($content, array_merge(...$case->evidence))) {
                $result->evidenceRank = $i + 1;
                break;
            }
        }

        if (! $withAnswers) {
            return $result;
        }

        $start = hrtime(true);
        $message = ($this->answerQuestion)($user, $case->question, $conversation);
        $result->answerMs = (hrtime(true) - $start) / 1e6;
        $result->answer = $message->content;

        $result->abstained = $message->content === AnswerQuestion::NO_CONTEXT_ANSWER
            || CitationRepairer::declinesToAnswer($message->content);

        foreach ($case->facts as $alternatives) {
            if ($this->containsAny($message->content, $alternatives)) {
                $result->factsFound++;
            } else {
                $result->missingFacts[] = implode(' | ', $alternatives);
            }
        }

        $sources = collect($message->sources ?? [])->keyBy('index');
        $cited = Chunk::whereIn('id', $sources->pluck('chunk_id'))->pluck('content', 'id');
        preg_match_all('/\[(\d+(?:\s*,\s*\d+)*)\]/', $message->content, $matches);

        foreach ($matches[1] as $group) {
            foreach (explode(',', $group) as $n) {
                $result->citations++;
                $source = $sources->get((int) trim($n));

                if (in_array($source['document_title'] ?? null, $case->expectDocuments, true)) {
                    $result->correctCitations++;
                }

                if ($source !== null && $this->hasEvidence((string) $cited->get($source['chunk_id']), array_merge(...$case->evidence))) {
                    $result->evidenceCitations++;
                }
            }
        }

        return $result;
    }

    /**
     * The conversation a follow-up question is asked in. With answers, the earlier questions
     * are answered for real (not counted towards the case); otherwise only they are stored.
     *
     * @param  list<string>  $earlier
     */
    private function conversation(User $user, array $earlier, bool $withAnswers): ?Conversation
    {
        if ($earlier === []) {
            return null;
        }

        if ($withAnswers) {
            $current = $this->current;
            $this->current = null;
            $conversation = null;

            try {
                foreach ($earlier as $question) {
                    $conversation = ($this->answerQuestion)($user, $question, $conversation)->conversation;
                }
            } finally {
                $this->current = $current;
            }

            return $conversation;
        }

        $conversation = $user->conversations()->create(['title' => Str::limit($earlier[0], 80)]);

        foreach ($earlier as $question) {
            $conversation->messages()->create(['role' => MessageRole::User, 'content' => $question]);
        }

        return $conversation;
    }

    /**
     * Whether any passage contains any of the alternatives.
     *
     * @param  list<string>  $passages
     * @param  list<string>  $alternatives
     */
    private function containsEvidence(array $passages, array $alternatives): bool
    {
        return collect($passages)->contains(fn (string $p) => $this->hasEvidence($p, $alternatives));
    }

    /**
     * @param  list<string>  $evidence
     */
    private function hasEvidence(string $passage, array $evidence): bool
    {
        return collect($evidence)->contains(fn (string $e) => str_contains(self::normalize($passage), self::normalize($e)));
    }

    /**
     * Lowercase with whitespace collapsed, so evidence matches across line wraps.
     */
    private static function normalize(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }

    /**
     * Case-insensitive match that doesn't let "30" match "300", and treats
     * "5,000", "5 000" and "5000" as the same number.
     *
     * @param  list<string>  $alternatives
     */
    private function containsAny(string $text, array $alternatives): bool
    {
        $text = self::normalizeNumbers($text);

        foreach (array_map(self::normalizeNumbers(...), $alternatives) as $alternative) {
            $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($alternative, '/').'(?![\p{L}\p{N}])/iu';

            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function normalizeNumbers(string $text): string
    {
        // Drop thousands separators (comma, space, thin/no-break space) between digit groups.
        return preg_replace('/(?<=\d)[,\x{00A0}\x{202F} ](?=\d{3}(?!\d))/u', '', $text) ?? $text;
    }
}

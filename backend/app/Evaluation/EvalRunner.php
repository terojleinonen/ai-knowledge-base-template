<?php

namespace App\Evaluation;

use App\Enums\DocumentStatus;
use App\Jobs\ProcessDocument;
use App\Models\User;
use App\Services\Chat\AnswerQuestion;
use App\Services\Chat\CitationRepairer;
use App\Services\Retrieval\Embedder;
use App\Services\Retrieval\SearchResult;
use App\Services\Retrieval\VectorStore;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Runs a dataset against the real ingestion, retrieval and answering pipeline
 * using a throwaway user, which is deleted (with its documents and files) afterwards.
 */
class EvalRunner
{
    public const USER_EMAIL_DOMAIN = 'kb-eval.invalid';

    public function __construct(
        private readonly Embedder $embedder,
        private readonly VectorStore $store,
        private readonly AnswerQuestion $answerQuestion,
    ) {}

    /**
     * @param  (Closure(CaseResult, int): void)|null  $onResult  called after each case with its 1-based number
     * @return list<CaseResult>
     */
    public function run(Dataset $dataset, bool $withAnswers = true, ?int $limit = null, ?Closure $onResult = null): array
    {
        self::cleanUp();

        $user = User::create([
            'name' => 'KB Eval',
            'email' => 'run-'.Str::lower((string) Str::ulid()).'@'.self::USER_EMAIL_DOMAIN,
            'password' => Str::random(40),
        ]);

        try {
            $this->ingest($user, $dataset->documents);

            $results = [];

            foreach (array_slice($dataset->cases, 0, $limit) as $i => $case) {
                $results[] = $result = $this->evaluate($user, $case, $withAnswers);
                $onResult?->__invoke($result, $i + 1);
            }

            return $results;
        } finally {
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
        $disk = config('knowledge.uploads.disk');

        foreach ($paths as $path) {
            $file = new UploadedFile($path, basename($path), null, null, true);
            $extension = strtolower($file->getClientOriginalExtension());

            $document = $user->documents()->create([
                'title' => pathinfo($path, PATHINFO_FILENAME),
                'original_name' => basename($path),
                'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                'size_bytes' => $file->getSize(),
                'disk' => $disk,
                'path' => $file->storeAs("documents/{$user->id}", Str::uuid().'.'.$extension, $disk),
                'checksum' => hash_file('sha256', $path),
            ]);

            ProcessDocument::dispatchSync($document);
            $document->refresh();

            if ($document->status !== DocumentStatus::Ready) {
                throw new RuntimeException("Could not ingest {$document->original_name}: ".($document->error ?? $document->status->value));
            }
        }
    }

    private function evaluate(User $user, EvalCase $case, bool $withAnswers): CaseResult
    {
        $result = new CaseResult($case);

        $start = hrtime(true);
        $hits = $this->store->search(
            userId: $user->id,
            queryVector: $this->embedder->embedQuery($case->question),
            embeddingModel: $this->embedder->identifier(),
            limit: (int) config('knowledge.retrieval.top_k'),
            minScore: (float) config('knowledge.retrieval.min_score'),
        );
        $result->retrievalMs = (hrtime(true) - $start) / 1e6;

        $result->retrievedDocuments = array_map(fn (SearchResult $hit) => $hit->documentTitle, $hits);
        $result->topScore = $hits[0]->score ?? 0.0;

        foreach ($result->retrievedDocuments as $i => $title) {
            if (in_array($title, $case->expectDocuments, true)) {
                $result->rank = $i + 1;
                break;
            }
        }

        if (! $withAnswers) {
            return $result;
        }

        $start = hrtime(true);
        $message = ($this->answerQuestion)($user, $case->question);
        $result->answerMs = (hrtime(true) - $start) / 1e6;
        $result->answer = $message->content;

        $result->abstained = $message->content === AnswerQuestion::NO_CONTEXT_ANSWER
            || CitationRepairer::isNotFoundStatement($message->content);

        foreach ($case->facts as $alternatives) {
            if ($this->containsAny($message->content, $alternatives)) {
                $result->factsFound++;
            } else {
                $result->missingFacts[] = implode(' | ', $alternatives);
            }
        }

        $sources = collect($message->sources ?? [])->keyBy('index');
        preg_match_all('/\[(\d+(?:\s*,\s*\d+)*)\]/', $message->content, $matches);

        foreach ($matches[1] as $group) {
            foreach (explode(',', $group) as $n) {
                $result->citations++;
                $title = $sources->get((int) trim($n))['document_title'] ?? null;

                if (in_array($title, $case->expectDocuments, true)) {
                    $result->correctCitations++;
                }
            }
        }

        return $result;
    }

    /**
     * Case-insensitive match that doesn't let "30" match "300".
     *
     * @param  list<string>  $alternatives
     */
    private function containsAny(string $text, array $alternatives): bool
    {
        foreach ($alternatives as $alternative) {
            $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($alternative, '/').'(?![\p{L}\p{N}])/iu';

            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }
}

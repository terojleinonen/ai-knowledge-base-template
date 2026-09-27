<?php

namespace App\Services\Chat;

use App\Ai\Agents\KnowledgeBaseAssistant;
use App\Enums\MessageRole;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Retrieval\Embedder;
use App\Services\Retrieval\SearchResult;
use App\Services\Retrieval\VectorStore;
use Generator;
use Illuminate\Http\StreamedEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Streaming\Events\TextDelta;
use Throwable;

class AnswerQuestion
{
    public const NO_CONTEXT_ANSWER = "I couldn't find anything relevant to that in your documents. Try rephrasing the question or uploading a document that covers it.";

    public function __construct(
        private readonly Embedder $embedder,
        private readonly VectorStore $store,
        private readonly CitationRepairer $citations,
    ) {}

    /**
     * Answer a question and persist the exchange.
     *
     * @param  list<int>|null  $documentIds
     */
    public function __invoke(User $user, string $question, ?Conversation $conversation = null, ?array $documentIds = null): Message
    {
        $results = $this->retrieve($user, $question, $documentIds);

        $answer = $results === []
            ? self::NO_CONTEXT_ANSWER
            : $this->agent($conversation)->prompt(
                $this->buildPrompt($question, $results),
                provider: config('knowledge.chat.provider'),
                model: config('knowledge.chat.model'),
                timeout: (int) config('knowledge.chat.timeout'),
            )->text;

        return $this->persist($user, $conversation, $question, $answer, $results);
    }

    /**
     * Answer a question as a stream of server-sent events:
     * "sources" once, "delta" per text fragment, then "done" (the saved message) or "error".
     *
     * The exchange is persisted when the answer completes, or with the partial
     * answer if the client disconnects mid-stream. Deltas carry the model's raw
     * text; the "done" message has citations verified against the sources.
     *
     * @param  list<int>|null  $documentIds
     * @return Generator<int, StreamedEvent>
     */
    public function stream(User $user, string $question, ?Conversation $conversation = null, ?array $documentIds = null): Generator
    {
        try {
            $results = $this->retrieve($user, $question, $documentIds);
        } catch (Throwable $e) {
            report($e);

            yield new StreamedEvent('error', ['message' => 'Your documents could not be searched right now. Please try again.']);

            return;
        }

        yield new StreamedEvent('sources', ['sources' => $this->sources($results)]);

        $answer = '';
        $message = null;
        $failed = false;

        try {
            try {
                $deltas = $results === []
                    ? [self::NO_CONTEXT_ANSWER]
                    : $this->streamDeltas($conversation, $this->buildPrompt($question, $results));

                foreach ($deltas as $delta) {
                    $answer .= $delta;

                    yield new StreamedEvent('delta', ['text' => $delta]);
                }
            } catch (Throwable $e) {
                report($e);
                $failed = true;

                yield new StreamedEvent('error', ['message' => 'The AI provider failed to answer. Please try again.']);

                return;
            }

            $message = $this->persist($user, $conversation, $question, $answer, $results);

            yield new StreamedEvent('done', (new MessageResource($message))->resolve());
        } finally {
            // Generator destroyed early: the client went away mid-answer. Keep what we have.
            if ($message === null && ! $failed && trim($answer) !== '') {
                $this->persist($user, $conversation, $question, $answer, $results);
            }
        }
    }

    /**
     * @return Generator<int, string>
     */
    private function streamDeltas(?Conversation $conversation, string $prompt): Generator
    {
        $events = $this->agent($conversation)->stream(
            $prompt,
            provider: config('knowledge.chat.provider'),
            model: config('knowledge.chat.model'),
            timeout: (int) config('knowledge.chat.timeout'),
        );

        foreach ($events as $event) {
            if ($event instanceof TextDelta && $event->delta !== '') {
                yield $event->delta;
            }
        }
    }

    /**
     * @param  list<int>|null  $documentIds
     * @return list<SearchResult>
     */
    private function retrieve(User $user, string $question, ?array $documentIds): array
    {
        return $this->store->search(
            userId: $user->id,
            queryVector: $this->embedder->embedQuery($question),
            embeddingModel: $this->embedder->identifier(),
            limit: (int) config('knowledge.retrieval.top_k'),
            minScore: (float) config('knowledge.retrieval.min_score'),
            documentIds: $documentIds,
        );
    }

    private function agent(?Conversation $conversation): KnowledgeBaseAssistant
    {
        return new KnowledgeBaseAssistant($conversation ? $this->history($conversation) : []);
    }

    /**
     * @param  list<SearchResult>  $results
     */
    private function persist(User $user, ?Conversation $conversation, string $question, string $answer, array $results): Message
    {
        return DB::transaction(function () use ($user, $conversation, $question, $answer, $results) {
            $conversation ??= $user->conversations()->create(['title' => Str::limit($question, 80)]);

            $conversation->messages()->create(['role' => MessageRole::User, 'content' => $question]);

            $message = $conversation->messages()->create([
                'role' => MessageRole::Assistant,
                'content' => config('knowledge.citations.repair')
                    ? $this->citations->repair(trim($answer), array_map(fn (SearchResult $r) => $r->content, $results))
                    : trim($answer),
                'sources' => $this->sources($results),
            ]);

            $conversation->touch();

            return $message;
        });
    }

    /**
     * @return list<UserMessage|AssistantMessage>
     */
    private function history(Conversation $conversation): array
    {
        return $conversation->messages()
            ->reorder('id', 'desc')
            ->limit((int) config('knowledge.history.messages'))
            ->get()
            ->reverse()
            ->map(fn (Message $m) => $m->role === MessageRole::User
                ? new UserMessage($m->content)
                : new AssistantMessage($m->content))
            ->values()
            ->all();
    }

    /**
     * @param  list<SearchResult>  $results
     */
    private function buildPrompt(string $question, array $results): string
    {
        $sources = collect($results)
            ->map(fn (SearchResult $r, int $i) => sprintf("[%d] (from \"%s\")\n%s", $i + 1, $r->documentTitle, $r->content))
            ->implode("\n\n---\n\n");

        return "Sources:\n\n{$sources}\n\n===\n\nQuestion: {$question}";
    }

    /**
     * @param  list<SearchResult>  $results
     * @return list<array{index: int, document_id: int, document_title: string, chunk_id: int, excerpt: string, score: float}>
     */
    private function sources(array $results): array
    {
        return array_map(fn (SearchResult $r, int $i) => [
            'index' => $i + 1,
            'document_id' => $r->documentId,
            'document_title' => $r->documentTitle,
            'chunk_id' => $r->chunkId,
            'excerpt' => Str::limit($r->content, 300),
            'score' => $r->score,
        ], $results, array_keys($results));
    }
}

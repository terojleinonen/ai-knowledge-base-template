<?php

namespace App\Services\Chat;

use App\Ai\Agents\KnowledgeBaseAssistant;
use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Retrieval\Embedder;
use App\Services\Retrieval\SearchResult;
use App\Services\Retrieval\VectorStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;

class AnswerQuestion
{
    public const NO_CONTEXT_ANSWER = "I couldn't find anything relevant to that in your documents. Try rephrasing the question or uploading a document that covers it.";

    public function __construct(
        private readonly Embedder $embedder,
        private readonly VectorStore $store,
    ) {}

    /**
     * @param  list<int>|null  $documentIds
     */
    public function __invoke(User $user, string $question, ?Conversation $conversation = null, ?array $documentIds = null): Message
    {
        $history = $conversation ? $this->history($conversation) : [];

        $results = $this->store->search(
            userId: $user->id,
            queryVector: $this->embedder->embed([$question])[0],
            embeddingModel: $this->embedder->identifier(),
            limit: (int) config('knowledge.retrieval.top_k'),
            minScore: (float) config('knowledge.retrieval.min_score'),
            documentIds: $documentIds,
        );

        $answer = $results === []
            ? self::NO_CONTEXT_ANSWER
            : (new KnowledgeBaseAssistant($history))->prompt(
                $this->buildPrompt($question, $results),
                provider: config('knowledge.chat.provider'),
                model: config('knowledge.chat.model'),
                timeout: (int) config('knowledge.chat.timeout'),
            )->text;

        return DB::transaction(function () use ($user, $conversation, $question, $answer, $results) {
            $conversation ??= $user->conversations()->create(['title' => Str::limit($question, 80)]);

            $conversation->messages()->create(['role' => MessageRole::User, 'content' => $question]);

            $message = $conversation->messages()->create([
                'role' => MessageRole::Assistant,
                'content' => trim($answer),
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

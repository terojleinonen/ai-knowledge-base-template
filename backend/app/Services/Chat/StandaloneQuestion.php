<?php

namespace App\Services\Chat;

use App\Ai\Agents\QueryRewriter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Throwable;

/**
 * The query to search the documents with. A follow-up ("How long must they be?") means
 * nothing to search on its own, so with conversation history it is either rewritten by a
 * small model call into a standalone question ("rewrite") or combined with the previous
 * question ("combine"). If the rewrite fails, the combined question is used.
 */
class StandaloneQuestion
{
    /** Recent messages shown to the rewriter, and how much of each answer. */
    private const CONTEXT_MESSAGES = 4;

    private const ANSWER_CHARS = 400;

    private const MAX_QUERY_CHARS = 500;

    /**
     * @param  list<UserMessage|AssistantMessage>  $history  oldest first
     */
    public function for(string $question, array $history): string
    {
        $previous = collect($history)->last(fn ($m) => $m instanceof UserMessage)?->content;

        if ($previous === null) {
            return $question;
        }

        return match (config('knowledge.follow_ups.mode')) {
            'rewrite' => $this->rewrite($question, $history) ?? $this->combine($previous, $question),
            'combine' => $this->combine($previous, $question),
            default => $question,
        };
    }

    private function combine(string $previous, string $question): string
    {
        return "{$previous}\n{$question}";
    }

    /**
     * @param  list<UserMessage|AssistantMessage>  $history
     */
    private function rewrite(string $question, array $history): ?string
    {
        $conversation = collect($history)
            ->slice(-self::CONTEXT_MESSAGES)
            ->map(fn ($m) => $m instanceof UserMessage
                ? "User: {$m->content}"
                // Answers are context only: shortened, without citation markers.
                : 'Assistant: '.Str::limit(trim((string) preg_replace('/\s*\[\d+(?:\s*,\s*\d+)*\]/', '', $m->content)), self::ANSWER_CHARS))
            ->implode("\n\n");

        try {
            $response = (new QueryRewriter)->prompt(
                "Conversation:\n\n{$conversation}\n\nLatest message: {$question}\n\nStandalone search query:",
                provider: config('knowledge.follow_ups.provider') ?: config('knowledge.chat.provider'),
                model: config('knowledge.follow_ups.model') ?: config('knowledge.chat.model'),
                timeout: (int) config('knowledge.follow_ups.timeout'),
            );
        } catch (Throwable $e) {
            Log::warning('Rewriting a follow-up question failed; combining it with the previous question', ['error' => $e->getMessage()]);

            return null;
        }

        $query = trim(Str::before(trim($response->text), "\n"), " \t\"'“”");

        return $query === '' || mb_strlen($query) > self::MAX_QUERY_CHARS ? null : $query;
    }
}

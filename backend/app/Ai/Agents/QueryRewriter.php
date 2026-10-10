<?php

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

/**
 * Turns a follow-up message ("How long must they be?") into a standalone search query
 * ("How long must passwords be?") using the conversation so far.
 */
class QueryRewriter implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'PROMPT'
        You rewrite the latest message of a conversation into a standalone search query for a
        document search engine.

        Rules:
        - Resolve references ("it", "they", "that", "the same", "what about...") using the
          conversation, so the query names its subject.
        - Keep the user's wording and language. Keep names, numbers and codes exactly.
        - If the message is already standalone or starts a new topic, return it unchanged.
        - Never answer the question. Reply with the query only: no quotes, no explanation.
        PROMPT;
    }
}

<?php

use App\Jobs\ProcessDocument;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
pest()->extend(TestCase::class)->in('Unit');

/**
 * Fake the embeddings provider with a deterministic bag-of-words vector, so
 * texts sharing words are "semantically" close and retrieval can be asserted.
 */
function fakeKeywordEmbeddings(int $dimensions = 64): void
{
    Embeddings::fake(fn (EmbeddingsPrompt $prompt) => array_map(
        fn (string $text) => keywordVector($text, $dimensions),
        $prompt->inputs,
    ))->preventStrayEmbeddings(false);
}

/**
 * @return list<float>
 */
function keywordVector(string $text, int $dimensions = 64): array
{
    $vector = array_fill(0, $dimensions, 0.0);

    foreach (preg_split('/\W+/u', mb_strtolower($text), flags: PREG_SPLIT_NO_EMPTY) as $word) {
        if (mb_strlen($word) > 3) {
            $vector[crc32($word) % $dimensions] += 1.0;
        }
    }

    $vector[$dimensions - 1] += 0.01; // never a zero vector

    return $vector;
}

/**
 * Store a text document for the user and process it synchronously (needs Storage::fake('local')).
 */
function ingest(User $user, string $title, string $content): Document
{
    $document = Document::factory()->for($user)->create(['title' => $title, 'path' => "documents/{$title}.txt"]);
    Storage::disk('local')->put($document->path, $content);
    ProcessDocument::dispatchSync($document);

    return $document->refresh();
}

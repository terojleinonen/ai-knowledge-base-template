<?php

use App\Enums\DocumentStatus;
use App\Jobs\ProcessDocument;
use App\Models\Document;
use App\Services\Retrieval\Embedder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('kb:reindex {--all : Reindex every document, not only those embedded with a different model}', function (Embedder $embedder) {
    $model = $embedder->identifier();

    $query = Document::query()
        ->where('status', '!=', DocumentStatus::Processing)
        ->when(! $this->option('all'), fn ($q) => $q->where(fn ($q) => $q
            ->whereNull('embedding_model')
            ->orWhere('embedding_model', '!=', $model)));

    $count = 0;

    $query->lazyById()->each(function (Document $document) use (&$count) {
        $document->update(['status' => DocumentStatus::Pending, 'error' => null]);
        ProcessDocument::dispatch($document);
        $count++;
    });

    $this->info("Queued {$count} document(s) for reindexing with [{$model}].");
})->purpose('Re-embed documents, e.g. after changing the embeddings model');

Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('queue:prune-failed --hours=168')->daily();

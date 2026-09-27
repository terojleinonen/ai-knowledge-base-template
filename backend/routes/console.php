<?php

use App\Enums\DocumentStatus;
use App\Jobs\ProcessDocument;
use App\Models\Document;
use App\Services\Demo\DemoAccounts;
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

Artisan::command('kb:demo:prepare {--force : Re-ingest even if the demo documents are up to date}', function (DemoAccounts $demo) {
    $count = $demo->prepare((bool) $this->option('force'));

    $this->info($count === 0 ? 'Demo documents are up to date.' : "Ingested {$count} demo document(s).");
})->purpose('Ingest the demo dataset into the template account used for guest sessions');

Artisan::command('kb:demo:prune {--hours= : Delete guests older than this (default: KB_DEMO_GUEST_TTL_HOURS)}', function (DemoAccounts $demo) {
    $hours = $this->option('hours');
    $count = $demo->prune($hours !== null ? (int) $hours : null);

    $this->info("Deleted {$count} guest account(s).");
})->purpose('Delete expired demo guest accounts');

Schedule::command('kb:demo:prune')->hourly()->when(fn () => config('knowledge.demo.enabled'));
Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('queue:prune-failed --hours=168')->daily();

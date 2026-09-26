<?php

namespace App\Providers;

use App\Services\Documents\TextChunker;
use App\Services\Retrieval\DatabaseVectorStore;
use App\Services\Retrieval\Embedder;
use App\Services\Retrieval\VectorStore;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(VectorStore::class, DatabaseVectorStore::class);

        $this->app->bind(TextChunker::class, fn () => new TextChunker(
            size: (int) config('knowledge.chunking.size'),
            overlap: (int) config('knowledge.chunking.overlap'),
        ));

        $this->app->bind(Embedder::class, fn () => new Embedder(
            provider: (string) config('knowledge.embeddings.provider'),
            model: config('knowledge.embeddings.model'),
            dimensions: config('knowledge.embeddings.dimensions'),
            batchSize: (int) config('knowledge.embeddings.batch_size'),
            timeout: (int) config('knowledge.embeddings.timeout'),
        ));
    }

    public function boot(): void
    {
        Password::defaults(fn () => $this->app->isProduction()
            ? Password::min(10)->uncompromised()
            : Password::min(8));

        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(30)->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('chat', fn (Request $request) => Limit::perMinute(20)->by($request->user()?->id ?: $request->ip()));
    }
}

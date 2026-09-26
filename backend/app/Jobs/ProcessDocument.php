<?php

namespace App\Jobs;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Services\Documents\DocumentProcessingException;
use App\Services\Documents\TextChunker;
use App\Services\Documents\TextExtractor;
use App\Services\Retrieval\Embedder;
use App\Services\Retrieval\VectorCodec;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessDocument implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    /** @var list<int> */
    public array $backoff = [15, 60, 300];

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Document $document) {}

    public function handle(TextExtractor $extractor, TextChunker $chunker, Embedder $embedder): void
    {
        $document = $this->document;
        $document->update(['status' => DocumentStatus::Processing, 'error' => null]);

        try {
            $contents = Storage::disk($document->disk)->get($document->path)
                ?? throw new DocumentProcessingException('The uploaded file could not be found.');

            $chunks = $chunker->split($extractor->extract($contents, $document->extension()));

            if ($chunks === []) {
                throw new DocumentProcessingException('No text could be extracted. Scanned PDFs need OCR before upload.');
            }
        } catch (DocumentProcessingException $e) {
            $this->fail($e);

            return;
        }

        $vectors = $embedder->embed($chunks);

        DB::transaction(function () use ($document, $chunks, $vectors, $embedder) {
            $document->chunks()->delete();

            foreach (array_chunk(array_keys($chunks), 200) as $positions) {
                $document->chunks()->insert(array_map(fn (int $i) => [
                    'document_id' => $document->id,
                    'user_id' => $document->user_id,
                    'position' => $i,
                    'content' => $chunks[$i],
                    'embedding' => VectorCodec::encode($vectors[$i]),
                ], $positions));
            }

            $document->update([
                'status' => DocumentStatus::Ready,
                'chunk_count' => count($chunks),
                'embedding_model' => $embedder->identifier(),
                'processed_at' => now(),
            ]);
        });
    }

    public function failed(?Throwable $exception): void
    {
        $message = $exception instanceof DocumentProcessingException
            ? $exception->getMessage()
            : 'Processing failed. Please try again later.';

        if (! $exception instanceof DocumentProcessingException) {
            Log::error('Document processing failed', [
                'document_id' => $this->document->id,
                'exception' => $exception,
            ]);
        }

        $this->document->update([
            'status' => DocumentStatus::Failed,
            'error' => $message,
        ]);
    }
}

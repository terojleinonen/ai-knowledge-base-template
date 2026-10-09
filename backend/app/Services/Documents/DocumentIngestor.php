<?php

namespace App\Services\Documents;

use App\Enums\DocumentStatus;
use App\Jobs\ProcessDocument;
use App\Models\Document;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Synchronously ingests a local file for a user (used by evals and the demo).
 */
class DocumentIngestor
{
    /**
     * @throws RuntimeException if the document could not be processed
     */
    public function ingest(User $user, string $path): Document
    {
        $disk = config('knowledge.uploads.disk');
        $file = new UploadedFile($path, basename($path), null, null, true);
        $extension = strtolower($file->getClientOriginalExtension());

        $document = $user->documents()->create([
            'title' => pathinfo($path, PATHINFO_FILENAME),
            'original_name' => basename($path),
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'size_bytes' => $file->getSize(),
            'disk' => $disk,
            'path' => $file->storeAs("documents/{$user->id}", Str::uuid().'.'.$extension, $disk)
                ?: throw new RuntimeException("Could not store {$file->getClientOriginalName()} on disk [{$disk}]."),
            'checksum' => hash_file('sha256', $path),
        ]);

        ProcessDocument::dispatchSync($document);
        $document->refresh();

        if ($document->status !== DocumentStatus::Ready) {
            throw new RuntimeException("Could not ingest {$document->original_name}: ".($document->error ?? $document->status->value));
        }

        return $document;
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Enums\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDocumentRequest;
use App\Http\Resources\DocumentResource;
use App\Jobs\ProcessDocument;
use App\Models\Document;
use App\Services\UsageLimits;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use League\Flysystem\FilesystemException;

class DocumentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['sometimes', Rule::enum(DocumentStatus::class)],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);

        $documents = $request->user()->documents()
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->latest('id')
            ->paginate($request->integer('per_page', 25));

        return DocumentResource::collection($documents);
    }

    public function store(StoreDocumentRequest $request, UsageLimits $limits): DocumentResource
    {
        $user = $request->user();

        if (! $limits->canAddDocument($user)) {
            throw ValidationException::withMessages([
                'file' => 'You have reached the limit of '.config('knowledge.limits.documents_per_user').' documents. Delete one to upload another.',
            ]);
        }

        $file = $request->file('file');
        $checksum = hash_file('sha256', $file->getRealPath());

        if ($user->documents()->where('checksum', $checksum)->exists()) {
            throw ValidationException::withMessages(['file' => 'You have already uploaded this file.']);
        }

        $disk = config('knowledge.uploads.disk');
        $extension = strtolower($file->getClientOriginalExtension());
        try {
            $path = $file->storeAs("documents/{$user->id}", Str::uuid().'.'.$extension, $disk);
        } catch (FilesystemException $e) {
            report($e);
            $path = false;
        }

        abort_if($path === false, 503, 'File storage is unavailable right now. Please try again later.');

        $document = $user->documents()->create([
            'title' => $request->input('title') ?: pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'size_bytes' => $file->getSize(),
            'disk' => $disk,
            'path' => $path,
            'checksum' => $checksum,
        ]);

        ProcessDocument::dispatch($document);

        return new DocumentResource($document->refresh());
    }

    public function show(Request $request, int $document): DocumentResource
    {
        return new DocumentResource($this->find($request, $document));
    }

    public function destroy(Request $request, int $document): Response
    {
        $this->find($request, $document)->delete();

        return response()->noContent();
    }

    public function reprocess(Request $request, int $document): DocumentResource
    {
        $model = $this->find($request, $document);

        abort_if($model->status === DocumentStatus::Processing, 409, 'This document is already being processed.');

        $model->update(['status' => DocumentStatus::Pending, 'error' => null]);

        ProcessDocument::dispatch($model);

        return new DocumentResource($model);
    }

    private function find(Request $request, int $id): Document
    {
        return $request->user()->documents()->findOrFail($id);
    }
}

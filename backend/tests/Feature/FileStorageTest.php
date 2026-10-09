<?php

use App\Enums\DocumentStatus;
use App\Jobs\ProcessDocument;
use App\Models\Document;
use App\Models\User;
use App\Services\Demo\DemoAccounts;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use League\Flysystem\UnableToWriteFile;

function bootWithEnv(array $env): void
{
    foreach ($env as $key => $value) {
        putenv("{$key}={$value}");
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
    test()->refreshApplication();
}

afterEach(function () {
    foreach (['KB_UPLOAD_DISK' => 'local', 'R2_BUCKET' => ''] as $key => $value) {
        putenv("{$key}={$value}");
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
});

it('uses R2 automatically once a bucket is configured', function () {
    bootWithEnv(['KB_UPLOAD_DISK' => '', 'R2_BUCKET' => 'kb-uploads']);
    expect(config('knowledge.uploads.disk'))->toBe('r2');

    bootWithEnv(['KB_UPLOAD_DISK' => '', 'R2_BUCKET' => '']);
    expect(config('knowledge.uploads.disk'))->toBe('local');

    bootWithEnv(['KB_UPLOAD_DISK' => 'local', 'R2_BUCKET' => 'kb-uploads']);
    expect(config('knowledge.uploads.disk'))->toBe('local'); // explicit setting wins
});

it('stores uploads on R2 and processes them from there', function () {
    Storage::fake('r2');
    fakeKeywordEmbeddings();
    config(['knowledge.uploads.disk' => 'r2']);
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/documents', ['file' => UploadedFile::fake()->createWithContent('notes.txt', 'Meeting notes about the product roadmap.')])
        ->assertCreated();

    $document = Document::sole();
    expect($document->disk)->toBe('r2')->and($document->status)->toBe(DocumentStatus::Ready);
    Storage::disk('r2')->assertExists($document->path);
});

it('reports unavailable file storage clearly instead of saving a document without a file', function () {
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('putFileAs')->andThrow(UnableToWriteFile::atLocation('documents/x.txt', 'connection refused'));
    Storage::set('r2', $disk);
    config(['knowledge.uploads.disk' => 'r2']);
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/documents', ['file' => UploadedFile::fake()->createWithContent('notes.txt', 'Some notes.')])
        ->assertServiceUnavailable()
        ->assertJsonPath('message', 'File storage is unavailable right now. Please try again later.');

    expect(Document::count())->toBe(0);
});

it('fails permanently when the stored file is gone', function () {
    Storage::fake('r2', ['throw' => true]);
    fakeKeywordEmbeddings();
    $document = Document::factory()->create(['disk' => 'r2', 'path' => 'documents/missing.txt']);

    ProcessDocument::dispatchSync($document);

    expect($document->refresh())
        ->status->toBe(DocumentStatus::Failed)
        ->error->toBe('The uploaded file could not be found. Please upload it again.');
});

it('moves the demo documents to R2 when the upload disk changes', function () {
    Storage::fake('local');
    Storage::fake('r2');
    fakeKeywordEmbeddings();
    config(['knowledge.demo.dataset' => base_path('tests/Fixtures/demo')]);
    $demo = app(DemoAccounts::class);

    expect($demo->prepare())->toBe(2);

    config(['knowledge.uploads.disk' => 'r2']);
    expect($demo->prepare())->toBe(2)->and($demo->prepare())->toBe(0);

    $template = User::where('email', DemoAccounts::TEMPLATE_EMAIL)->sole();
    expect($template->documents()->pluck('disk')->unique()->all())->toBe(['r2']);
    foreach ($template->documents as $document) {
        Storage::disk('r2')->assertExists($document->path);
    }
});

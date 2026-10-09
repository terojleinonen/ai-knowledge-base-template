<?php

use App\Models\Document;
use App\Models\User;
use App\Services\UsageLimits;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
    config(['knowledge.uploads.disk' => 'local', 'knowledge.limits.storage_mb' => 1]); // 1 MB quota
    Sanctum::actingAs($this->user = User::factory()->create());
});

function kilobytes(int $kb): UploadedFile
{
    $unique = uniqid('', true); // distinct content, or the duplicate-upload check refuses it first

    return UploadedFile::fake()->createWithContent("{$unique}.txt", $unique.str_repeat('a', $kb * 1024 - strlen($unique)));
}

it('accepts uploads that fit and refuses one that would exceed the quota, before storing it', function () {
    Document::factory()->create(['disk' => 'local', 'size_bytes' => 900 * 1024]);

    $this->postJson('/api/documents', ['file' => kilobytes(100)])->assertCreated(); // 1000 KB of 1024

    $this->postJson('/api/documents', ['file' => kilobytes(100)])
        ->assertStatus(507)
        ->assertJsonPath('message', 'Storage is full right now. Please try again later.');

    expect(Document::count())->toBe(2)
        ->and(Storage::disk('local')->allFiles())->toHaveCount(1); // the refused file was never written
});

it('counts files shared by demo guests once', function () {
    // The template's file and two guest copies point at the same stored file.
    Document::factory()->count(3)->create(['disk' => 'local', 'path' => 'documents/1/shared.pdf', 'size_bytes' => 600 * 1024]);

    expect(app(UsageLimits::class)->storedBytes('local'))->toBe(600 * 1024);
    $this->postJson('/api/documents', ['file' => kilobytes(300)])->assertCreated();
});

it('only counts files on the upload disk', function () {
    Document::factory()->create(['disk' => 'old-disk', 'size_bytes' => 5 * 1024 * 1024]);

    $this->postJson('/api/documents', ['file' => kilobytes(100)])->assertCreated();
});

it('frees quota when documents are deleted', function () {
    $this->postJson('/api/documents', ['file' => kilobytes(700)])->assertCreated();
    $this->postJson('/api/documents', ['file' => kilobytes(700)])->assertStatus(507);

    $this->deleteJson('/api/documents/'.Document::sole()->id)->assertNoContent();
    $this->postJson('/api/documents', ['file' => kilobytes(700)])->assertCreated();
});

it('has no quota when unset', function () {
    config(['knowledge.limits.storage_mb' => null]);
    Document::factory()->create(['disk' => 'local', 'size_bytes' => 50 * 1024 * 1024]);

    $this->postJson('/api/documents', ['file' => kilobytes(100)])->assertCreated();
});

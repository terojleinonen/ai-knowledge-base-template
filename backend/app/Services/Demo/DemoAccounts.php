<?php

namespace App\Services\Demo;

use App\Enums\DocumentStatus;
use App\Evaluation\Dataset;
use App\Models\Document;
use App\Models\User;
use App\Services\Documents\DocumentIngestor;
use App\Services\Retrieval\Embedder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Guest accounts for a public demo.
 *
 * The demo documents are ingested once into a template account. Each guest
 * gets a copy of the template's documents and chunks (database rows only: no
 * re-embedding and no file copies), so starting a demo costs no API credit.
 */
class DemoAccounts
{
    public const EMAIL_DOMAIN = 'kb-demo.invalid';

    public const TEMPLATE_EMAIL = 'template@'.self::EMAIL_DOMAIN;

    public function __construct(
        private readonly DocumentIngestor $ingestor,
        private readonly Embedder $embedder,
    ) {}

    /**
     * Ingest the demo dataset into the template account unless it is already up to date.
     *
     * @return int number of documents ingested (0 if already up to date)
     */
    public function prepare(bool $force = false): int
    {
        $paths = Dataset::load($this->datasetPath())->documents;
        $template = $this->template();

        $ready = $template->documents()
            ->where('status', DocumentStatus::Ready)
            ->where('embedding_model', $this->embedder->identifier())
            ->where('disk', config('knowledge.uploads.disk')) // e.g. moved from local disk to R2
            ->pluck('original_name')
            ->all();

        $expected = array_map('basename', $paths);
        sort($ready);
        sort($expected);

        if (! $force && $ready === $expected) {
            return 0;
        }

        $template->documents()->get()->each->delete();

        foreach ($paths as $path) {
            $this->ingestor->ingest($template, $path);
        }

        return count($paths);
    }

    public function createGuest(): User
    {
        $this->prepare();

        return DB::transaction(function () {
            $guest = User::forceCreate([
                'name' => 'Guest',
                'email' => 'guest-'.Str::lower((string) Str::ulid()).'@'.self::EMAIL_DOMAIN,
                'password' => Str::random(40),
                'is_guest' => true,
            ]);

            foreach ($this->template()->documents()->where('status', DocumentStatus::Ready)->get() as $source) {
                $copy = $source->replicate(['user_id']);
                $copy->user_id = $guest->id;
                $copy->save();

                DB::table('chunks')->insertUsing(
                    ['document_id', 'user_id', 'position', 'content', 'embedding'],
                    DB::table('chunks')
                        ->selectRaw('?, ?, position, content, embedding', [$copy->id, $guest->id])
                        ->where('document_id', $source->id),
                );
            }

            return $guest;
        });
    }

    /**
     * Delete guest accounts older than the configured lifetime.
     *
     * @return int number of guests deleted
     */
    public function prune(?int $olderThanHours = null): int
    {
        $cutoff = now()->subHours($olderThanHours ?? (int) config('knowledge.demo.guest_ttl_hours'));
        $count = 0;

        User::where('is_guest', true)->where('created_at', '<', $cutoff)->lazyById()->each(function (User $guest) use (&$count) {
            $guest->documents()->get()->each(fn (Document $d) => $d->delete());
            $guest->tokens()->delete();
            $guest->delete();
            $count++;
        });

        return $count;
    }

    private function template(): User
    {
        return User::firstOrCreate(
            ['email' => self::TEMPLATE_EMAIL],
            ['name' => 'Demo template', 'password' => Str::random(40)],
        );
    }

    private function datasetPath(): string
    {
        $path = (string) config('knowledge.demo.dataset');

        return str_starts_with($path, '/') ? $path : base_path($path);
    }
}

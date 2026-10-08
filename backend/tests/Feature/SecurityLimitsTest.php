<?php

use App\Ai\Agents\KnowledgeBaseAssistant;
use App\Enums\DocumentStatus;
use App\Jobs\ProcessDocument;
use App\Models\Document;
use App\Models\User;
use App\Services\Documents\DocumentProcessingException;
use App\Services\Documents\TextExtractor;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Embeddings;
use Laravel\Sanctum\Sanctum;

function withTrustedProxies(string $value): void
{
    putenv("TRUSTED_PROXIES={$value}");
    $_ENV['TRUSTED_PROXIES'] = $_SERVER['TRUSTED_PROXIES'] = $value;
    test()->refreshApplication();
}

afterEach(function () {
    putenv('TRUSTED_PROXIES=');
    $_ENV['TRUSTED_PROXIES'] = $_SERVER['TRUSTED_PROXIES'] = '';
});

describe('client IP behind a proxy', function () {
    it('shares one limit for every visitor when proxies are not trusted', function () {
        $first = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.5'])->getJson('/api/client-ip');
        $second = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7'])->getJson('/api/client-ip');

        expect($first->json('ip'))->toBe('10.0.0.1')->and($second->json('ip'))->toBe('10.0.0.1')
            ->and($second->headers->get('X-RateLimit-Remaining'))->toBe('8');
    });

    it('uses the forwarded visitor IP when the proxy is trusted', function () {
        withTrustedProxies('10.0.0.0/8');

        $first = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.5'])->getJson('/api/client-ip');
        $second = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7'])->getJson('/api/client-ip');

        expect($first->json('ip'))->toBe('203.0.113.5')->and($second->json('ip'))->toBe('198.51.100.7')
            ->and($second->headers->get('X-RateLimit-Remaining'))->toBe('9'); // separate counters
    });

    it('ignores addresses a visitor forges in X-Forwarded-For', function () {
        withTrustedProxies('10.0.0.0/8');

        // The visitor sent "1.2.3.4"; the trusted proxy appended the real address.
        $response = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 203.0.113.5'])->getJson('/api/client-ip');

        expect($response->json('ip'))->toBe('203.0.113.5');
    });

    it('refuses TRUSTED_PROXIES="*", which would let visitors forge their IP', function () {
        expect(fn () => withTrustedProxies('*'))->toThrow(InvalidArgumentException::class, 'forge their IP');

        withTrustedProxies(''); // rebuild a working application for the rest of the suite
    });

    it('can read the visitor IP from an edge header, ignoring invalid values', function () {
        config(['knowledge.client_ip_header' => 'CF-Connecting-IP']);

        expect($this->withHeader('CF-Connecting-IP', '203.0.113.9')->getJson('/api/client-ip')->json('ip'))->toBe('203.0.113.9');
        expect($this->withHeader('CF-Connecting-IP', 'not-an-ip')->getJson('/api/client-ip')->json('ip'))->toBe('127.0.0.1');
    });
});

describe('demo abuse limits', function () {
    beforeEach(function () {
        Storage::fake('local');
        fakeKeywordEmbeddings();
        config(['knowledge.demo.enabled' => true, 'knowledge.demo.dataset' => base_path('tests/Fixtures/demo')]);
    });

    it('caps new guest sessions per day', function () {
        config(['knowledge.limits.guests_per_day' => 2]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.1'])->postJson('/api/auth/guest')->assertCreated();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.2'])->postJson('/api/auth/guest')->assertCreated();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.3'])->postJson('/api/auth/guest')
            ->assertTooManyRequests()
            ->assertJsonPath('message', 'The demo has reached its limit of new sessions for today. Please come back tomorrow.');
    });

    it('rate limits guest creation per visitor when enabled', function () {
        config(['knowledge.limits.guests_per_ip_per_hour' => 3]);

        foreach (range(1, 3) as $i) {
            $this->postJson('/api/auth/guest')->assertCreated();
        }

        $this->postJson('/api/auth/guest')->assertTooManyRequests();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])->postJson('/api/auth/guest')->assertCreated();
    });

    it('caps questions per visitor IP across guest accounts', function () {
        KnowledgeBaseAssistant::fake();
        config(['knowledge.limits.questions_per_ip_per_day' => 1]);

        foreach ([User::factory()->create(), User::factory()->create()] as $i => $user) {
            Sanctum::actingAs($user);
            $response = $this->postJson('/api/chat', ['question' => 'Anything about vacation?']);
            $i === 0 ? $response->assertCreated() : $response->assertTooManyRequests();
        }
    });
});

describe('upload size limits', function () {
    beforeEach(function () {
        Storage::fake('local');
        fakeKeywordEmbeddings();
        $this->user = User::factory()->create();
    });

    function storedDocument(User $user, string $text): Document
    {
        Storage::disk('local')->put($path = 'documents/'.uniqid().'.txt', $text);

        return Document::factory()->for($user)->create(['path' => $path]);
    }

    it('refuses documents that exceed the account\'s passage limit before embedding them', function () {
        config(['knowledge.limits.chunks_per_user' => 3, 'knowledge.chunking.size' => 100, 'knowledge.chunking.overlap' => 10]);
        $embeddingCalls = 0;
        Embeddings::fake(function ($prompt) use (&$embeddingCalls) {
            $embeddingCalls++;

            return array_map(fn ($t) => keywordVector($t), $prompt->inputs);
        });

        $small = storedDocument($this->user, 'Short note about vacation days.');
        ProcessDocument::dispatchSync($small);
        expect($small->refresh()->status)->toBe(DocumentStatus::Ready);

        $large = storedDocument($this->user, str_repeat('A long policy paragraph about many different topics. ', 40));
        ProcessDocument::dispatchSync($large);

        expect($large->refresh())
            ->status->toBe(DocumentStatus::Failed)
            ->error->toContain('your account has room for 2 more')
            ->and($embeddingCalls)->toBe(1); // only the small document was embedded

        // Reprocessing a document doesn't count its own existing passages against it.
        ProcessDocument::dispatchSync($small->refresh());
        expect($small->refresh()->status)->toBe(DocumentStatus::Ready);
    });

    it('rejects DOCX zip bombs without unpacking them', function () {
        config(['knowledge.uploads.max_docx_xml_bytes' => 1024 * 1024]);

        $path = tempnam(sys_get_temp_dir(), 'bomb');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml', str_repeat('<w:p/>', 400_000)); // ~2.4 MB, compresses to a few KB
        $zip->close();

        expect(filesize($path))->toBeLessThan(100_000);
        expect(fn () => app(TextExtractor::class)->extract(file_get_contents($path), 'docx'))
            ->toThrow(DocumentProcessingException::class, 'too large to process');

        unlink($path);
    });
});

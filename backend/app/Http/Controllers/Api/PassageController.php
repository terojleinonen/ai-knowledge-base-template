<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Chunk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A cited passage with the text around it, so a citation can be read in context.
 */
class PassageController extends Controller
{
    /** Passages shown on each side of the cited one. */
    private const CONTEXT = 2;

    /** Shortest overlap treated as the chunker's carried-over text rather than a coincidence. */
    private const MIN_OVERLAP = 8;

    public function show(Request $request, int $chunk): JsonResponse
    {
        $passage = Chunk::query()
            ->where('user_id', $request->user()->id)
            ->with('document:id,title,chunk_count')
            ->findOrFail($chunk);

        $neighbours = Chunk::query()
            ->where('document_id', $passage->document_id)
            ->whereBetween('position', [$passage->position - self::CONTEXT, $passage->position + self::CONTEXT])
            ->where('id', '!=', $passage->id)
            ->orderBy('position')
            ->get(['id', 'position', 'content']);

        // Consecutive chunks overlap (each starts with the end of the previous one). Keep the
        // cited passage whole and trim the overlap from its neighbours instead.
        $before = [];
        $next = $passage->content;

        foreach ($neighbours->where('position', '<', $passage->position)->sortByDesc('position') as $chunkBefore) {
            array_unshift($before, self::withoutSuffix($chunkBefore->content, $next));
            $next = $chunkBefore->content;
        }

        $after = [];
        $previous = $passage->content;

        foreach ($neighbours->where('position', '>', $passage->position) as $chunkAfter) {
            $after[] = self::withoutPrefix($chunkAfter->content, $previous);
            $previous = $chunkAfter->content;
        }

        return response()->json(['data' => [
            'chunk_id' => $passage->id,
            'document' => ['id' => $passage->document->id, 'title' => $passage->document->title],
            'position' => $passage->position,
            'total' => $passage->document->chunk_count,
            'before' => array_values(array_filter($before, fn (string $t) => $t !== '')),
            'content' => $passage->content,
            'after' => array_values(array_filter($after, fn (string $t) => $t !== '')),
        ]]);
    }

    /**
     * $text without its end where it repeats the start of $following.
     */
    private static function withoutSuffix(string $text, string $following): string
    {
        $overlap = self::overlap($text, $following);

        return rtrim(mb_substr($text, 0, mb_strlen($text) - $overlap));
    }

    /**
     * $text without its start where it repeats the end of $preceding.
     */
    private static function withoutPrefix(string $text, string $preceding): string
    {
        return ltrim(mb_substr($text, self::overlap($preceding, $text)));
    }

    /**
     * Length of the longest end of $first that $second starts with.
     */
    private static function overlap(string $first, string $second): int
    {
        $max = min(mb_strlen($first), mb_strlen($second));

        for ($length = $max; $length >= self::MIN_OVERLAP; $length--) {
            if (mb_substr($first, -$length) === mb_substr($second, 0, $length)) {
                return $length;
            }
        }

        return 0;
    }
}

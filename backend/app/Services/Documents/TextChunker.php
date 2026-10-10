<?php

namespace App\Services\Documents;

use InvalidArgumentException;

/**
 * Splits text into overlapping chunks, preferring paragraph, line, sentence
 * and word boundaries (in that order) before falling back to hard splits.
 *
 * Chunks follow the document's sections: a heading starts a new chunk once the current
 * one holds some text, a chunk never ends with a dangling heading, and no overlap is
 * carried across a heading (it would mix two sections in one passage). A heading line may
 * push a chunk past the size rather than being split from its text.
 */
class TextChunker
{
    private const SEPARATORS = ["\n\n", "\n", '. ', ' '];

    /** A heading starts a new chunk only once the current one has at least this share of the size. */
    private const MIN_SECTION_SHARE = 0.15;

    public function __construct(
        private readonly int $size = 1200,
        private readonly int $overlap = 200,
    ) {
        if ($size < 1 || $overlap < 0 || $overlap >= $size) {
            throw new InvalidArgumentException('Chunk overlap must be non-negative and smaller than the chunk size.');
        }
    }

    /**
     * @return list<string>
     */
    public function split(string $text): array
    {
        return array_column($this->chunks($text), 'content');
    }

    /**
     * Chunks with the heading of the section each one continues ("section" is null when the
     * chunk starts with its own heading, or comes before the first heading).
     *
     * @return list<array{content: string, section: string|null}>
     */
    public function chunks(string $text): array
    {
        $text = trim($text);

        if ($text === '') {
            return [];
        }

        return $this->merge($this->atomize($text, self::SEPARATORS));
    }

    /**
     * Break text into pieces no longer than the chunk size.
     *
     * @param  list<string>  $separators
     * @return list<string>
     */
    private function atomize(string $text, array $separators): array
    {
        if (mb_strlen($text) <= $this->size) {
            return [$text];
        }

        foreach ($separators as $i => $separator) {
            if (! str_contains($text, $separator)) {
                continue;
            }

            $parts = explode($separator, $text);
            $last = array_key_last($parts);
            $pieces = [];

            foreach ($parts as $key => $part) {
                $part = $key === $last ? $part : $part.$separator;

                array_push($pieces, ...$this->atomize($part, array_slice($separators, $i + 1)));
            }

            return $pieces;
        }

        return mb_str_split($text, $this->size);
    }

    /**
     * Greedily merge pieces into chunks, carrying trailing pieces forward as overlap.
     *
     * @param  list<string>  $pieces
     * @return list<array{content: string, section: string|null}>
     */
    private function merge(array $pieces): array
    {
        $chunks = [];
        $window = []; // list of [piece, section heading in effect for that piece]
        $length = 0;
        $section = null;

        foreach ($pieces as $piece) {
            $pieceLength = mb_strlen($piece);
            $heading = self::isHeading($piece);
            $texts = array_column($window, 0);

            if ($window !== [] && $heading && $length >= $this->size * self::MIN_SECTION_SHARE) {
                // New section: start a fresh chunk, without overlap from the previous section.
                $chunks[] = self::chunkOf($window);
                $window = [];
            } elseif ($window !== [] && $length + $pieceLength > $this->size && ! self::onlyHeadings($texts)) {
                // (A window of only headings stays with the text after it, even if that pushes
                // the chunk past the size by a heading line.)
                // Headings at the end of the window belong to the text after them.
                $headings = [];

                while (count($window) > 1 && self::isHeading($window[array_key_last($window)][0])) {
                    array_unshift($headings, array_pop($window));
                }

                $chunks[] = self::chunkOf($window);

                if ($headings !== []) {
                    $window = $headings;
                } else {
                    while ($window !== [] && ($length > $this->overlap || $length + $pieceLength > $this->size)) {
                        $length -= mb_strlen(array_shift($window)[0]);
                    }
                }
            }

            if ($heading) {
                $section = self::headingText($piece);
            }

            $window[] = [$piece, $section];
            $length = array_sum(array_map(fn (array $entry) => mb_strlen($entry[0]), $window));
        }

        if ($window !== []) {
            $chunks[] = self::chunkOf($window);
        }

        return array_values(array_filter($chunks, fn (array $chunk) => $chunk['content'] !== ''));
    }

    /**
     * @param  non-empty-list<array{0: string, 1: string|null}>  $window  pieces with the section each belongs to
     * @return array{content: string, section: string|null}
     */
    private static function chunkOf(array $window): array
    {
        [$first, $section] = $window[0];

        return ['content' => trim(implode('', array_column($window, 0))), 'section' => self::isHeading($first) ? null : $section];
    }

    private static function headingText(string $piece): string
    {
        return mb_substr(trim((string) preg_replace('/^#{1,6}\s+/u', '', trim($piece))), 0, 255);
    }

    /**
     * @param  list<string>  $pieces
     */
    private static function onlyHeadings(array $pieces): bool
    {
        return array_filter($pieces, fn (string $p) => ! self::isHeading($p)) === [];
    }

    /**
     * A Markdown heading ("## Pets"), or a short single line without closing punctuation
     * that starts with a capital or a section number ("6. Daily allowances by country",
     * "Passwords and authentication") - how headings look once extracted from PDF and DOCX.
     */
    private static function isHeading(string $piece): bool
    {
        $line = trim($piece);

        if ($line === '' || str_contains($line, "\n")) {
            return false;
        }

        if (preg_match('/^#{1,6}\s+\S/u', $line)) {
            return true;
        }

        return mb_strlen($line) <= 80
            && preg_match('/^(\d+(\.\d+)*\.?\s+)?\p{Lu}/u', $line) === 1
            && preg_match('/[.:;,!?)\]]$/u', $line) === 0;
    }
}

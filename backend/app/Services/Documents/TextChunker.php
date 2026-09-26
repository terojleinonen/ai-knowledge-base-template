<?php

namespace App\Services\Documents;

use InvalidArgumentException;

/**
 * Splits text into overlapping chunks, preferring paragraph, line, sentence
 * and word boundaries (in that order) before falling back to hard splits.
 */
class TextChunker
{
    private const SEPARATORS = ["\n\n", "\n", '. ', ' '];

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
     * @return list<string>
     */
    private function merge(array $pieces): array
    {
        $chunks = [];
        $window = [];
        $length = 0;

        foreach ($pieces as $piece) {
            $pieceLength = mb_strlen($piece);

            if ($window !== [] && $length + $pieceLength > $this->size) {
                $chunks[] = implode('', $window);

                while ($window !== [] && ($length > $this->overlap || $length + $pieceLength > $this->size)) {
                    $length -= mb_strlen(array_shift($window));
                }
            }

            $window[] = $piece;
            $length += $pieceLength;
        }

        if ($window !== []) {
            $chunks[] = implode('', $window);
        }

        return array_values(array_filter(array_map('trim', $chunks), fn (string $c) => $c !== ''));
    }
}

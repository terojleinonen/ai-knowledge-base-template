<?php

namespace App\Services\Retrieval;

/**
 * Splits text into search terms for keyword (BM25) search: lowercase words and numbers,
 * alphanumerics kept whole ("1password", "ip54", "lte-m", "2.5"), common words dropped,
 * and a light plural stemmer so "days" matches "day".
 */
final class Tokenizer
{
    private const STOPWORDS = [
        'a', 'an', 'and', 'are', 'as', 'at', 'be', 'but', 'by', 'can', 'do', 'does', 'for', 'from', 'get', 'has',
        'have', 'how', 'i', 'if', 'in', 'is', 'it', 'its', 'many', 'may', 'me', 'much', 'must', 'my', 'no', 'not',
        'of', 'on', 'or', 'our', 'should', 'so', 'than', 'that', 'the', 'their', 'there', 'they', 'this', 'to',
        'was', 'we', 'what', 'when', 'where', 'which', 'who', 'why', 'will', 'with', 'would', 'you', 'your',
    ];

    /**
     * @return list<string> terms in order, duplicates kept (for term frequencies)
     */
    public static function tokens(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]+(?:[.,\'-][\p{L}\p{N}]+)*/u', str_replace('’', "'", mb_strtolower($text)), $matches);

        $tokens = [];

        foreach ($matches[0] as $word) {
            $numeric = is_numeric(str_replace(',', '.', $word));

            if (! $numeric && (mb_strlen($word) < 2 || in_array($word, self::STOPWORDS, true))) {
                continue;
            }

            if (str_ends_with($word, "'s")) {
                $word = mb_substr($word, 0, -2); // possessive: "bob's" → "bob"
            } elseif (! $numeric && mb_strlen($word) > 3 && str_ends_with($word, 's') && ! str_ends_with($word, 'ss')) {
                $word = mb_substr($word, 0, -1);
            }

            $tokens[] = $word;
        }

        return $tokens;
    }
}

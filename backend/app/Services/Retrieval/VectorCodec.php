<?php

namespace App\Services\Retrieval;

/**
 * Compact, database-portable storage for embedding vectors: base64-encoded
 * little-endian float32. About 2.5x smaller than JSON and much faster to decode.
 */
final class VectorCodec
{
    /**
     * @param  array<int, float|int>  $vector
     */
    public static function encode(array $vector): string
    {
        return base64_encode(pack('g*', ...$vector));
    }

    /**
     * @return list<float>
     */
    public static function decode(string $encoded): array
    {
        return array_values(unpack('g*', base64_decode($encoded, true) ?: '') ?: []);
    }

    /**
     * @param  array<int, float|int>  $vector
     * @return list<float>
     */
    public static function normalize(array $vector): array
    {
        $magnitude = sqrt(array_sum(array_map(fn ($v) => $v * $v, $vector)));

        if ($magnitude == 0.0) {
            return array_values(array_map('floatval', $vector));
        }

        return array_values(array_map(fn ($v) => $v / $magnitude, $vector));
    }

    /**
     * Dot product; equals cosine similarity for unit-length vectors.
     *
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    public static function dot(array $a, array $b): float
    {
        $sum = 0.0;

        foreach ($a as $i => $value) {
            $sum += $value * $b[$i];
        }

        return $sum;
    }
}

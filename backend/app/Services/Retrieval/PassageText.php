<?php

namespace App\Services\Retrieval;

/**
 * The text a passage is embedded and reranked as: its document title and section heading
 * in front of its content, so a passage that continues a section ("For countries not
 * listed...") still says what it is about. The stored and displayed content stays as is.
 */
final class PassageText
{
    public static function of(string $documentTitle, ?string $section, string $content): string
    {
        return $documentTitle.($section !== null && $section !== '' ? " > {$section}" : '')."\n\n".$content;
    }
}
